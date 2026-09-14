<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsRequestBody;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use BuildsRequestBody;
    use CallsTools;
    use ProcessRateLimits;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        yield from $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->state->reset()->withMessageId(EventID::generate());
        $reasoningItems = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($data['type'] === 'error') {
                $code = data_get($data, 'error.code', 'unknown_error');
                $message = data_get($data, 'error.message', 'No error message provided');

                if ($code === 'rate_limit_exceeded') {
                    throw new PrismRateLimitedException([]);
                }

                throw new PrismException(sprintf(
                    'Sending to model %s failed. Code: %s. Message: %s',
                    $request->model(),
                    $code,
                    $message
                ));
            }

            if ($data['type'] === 'response.created' && $this->state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $data['response']['model'] ?? 'unknown',
                    provider: 'openai',
                );

                $this->state->markStreamStarted();

                continue;
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            if ($this->hasReasoningSummaryDelta($data)) {
                $reasoningDelta = $this->extractReasoningSummaryDelta($data);

                if ($reasoningDelta !== '') {
                    if ($this->state->reasoningId() === '') {
                        $this->state->withReasoningId(EventID::generate());
                        yield new ThinkingStartEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            reasoningId: $this->state->reasoningId()
                        );
                    }

                    $this->state->appendThinking($reasoningDelta);

                    yield new ThinkingEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        delta: $reasoningDelta,
                        reasoningId: $this->state->reasoningId()
                    );
                }

                continue;
            }

            if (data_get($data, 'type') === 'response.output_item.done') {
                $item = data_get($data, 'item', []);
                $itemType = data_get($item, 'type', '');

                if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $itemType,
                        status: 'completed',
                        itemId: data_get($item, 'id', ''),
                        data: $item
                    );

                    continue;
                }
            }

            if ($this->hasReasoningItems($data)) {
                $reasoningItems = $this->extractReasoningItems($data, $reasoningItems);

                if ($this->state->reasoningId() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                    $this->state->withReasoningId('');
                }

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCallDeltaEvent = $this->extractToolCalls($data, $reasoningItems);

                if ($toolCallDeltaEvent instanceof ToolCallDeltaEvent) {
                    yield $toolCallDeltaEvent;
                }

                if ($this->isToolCallComplete($data)) {
                    $completedToolCall = $this->getCompletedToolCall($data);
                    if ($completedToolCall instanceof ToolCall) {
                        yield new ToolCallEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolCall: $completedToolCall,
                            messageId: $this->state->messageId()
                        );
                    }
                }

                continue;
            }

            $type = (string) data_get($data, 'type', '');

            if (str_starts_with($type, 'response.') && str_contains($type, '_call.')) {
                $parts = explode('.', $type, 3);

                if (count($parts) === 3 && str_ends_with($parts[1], '_call')) {
                    $toolType = $parts[1];
                    $status = $parts[2];

                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $toolType,
                        status: $status,
                        itemId: data_get($data, 'item_id', ''),
                        data: $data
                    );

                    continue;
                }
            }

            $content = $this->extractOutputTextDelta($data);

            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                    $this->state->markTextStarted();
                }

                $this->state->appendText($content);

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId()
                );
            }

            if (data_get($data, 'type') === 'response.output_text.done' && $this->state->hasTextStarted()) {
                $this->state->markTextCompleted();

                yield new TextCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId()
                );
            }

            if (data_get($data, 'type') === 'response.completed') {
                $this->state->withFinishReason($this->mapFinishReason($data));
                $this->state->addUsage(new Usage(
                    promptTokens: data_get($data, 'response.usage.input_tokens'),
                    completionTokens: data_get($data, 'response.usage.output_tokens'),
                    cacheReadInputTokens: data_get($data, 'response.usage.input_tokens_details.cached_tokens'),
                    thoughtTokens: data_get($data, 'response.usage.output_tokens_details.reasoning_tokens')
                ));
                $this->state->withMetadata(['response_id' => data_get($data, 'response.id')]);
            }
        }

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);

            return;
        }

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        yield $this->emitStreamEndEvent();
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage() ?? new Usage(0, 0),
            additionalContent: Arr::whereNotNull([
                'response_id' => $this->state->metadata()['response_id'] ?? null,
                'reasoningSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
            ])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, 'DONE')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('OpenAI', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     */
    protected function extractToolCalls(array $data, array $reasoningItems = []): ?ToolCallDeltaEvent
    {
        $type = data_get($data, 'type', '');

        if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
            $index = (int) data_get($data, 'output_index', count($this->state->toolCalls()));

            $toolCall = [
                'id' => data_get($data, 'item.id'),
                'call_id' => data_get($data, 'item.call_id'),
                'name' => data_get($data, 'item.name'),
                'arguments' => '',
            ];

            // Associate with the most recent reasoning item if available
            if ($reasoningItems !== []) {
                $latestReasoning = end($reasoningItems);
                $toolCall['reasoning_id'] = $latestReasoning['id'];
                $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
            }

            $this->state->addToolCall($index, $toolCall);

            return null;
        }

        if ($type === 'response.function_call_arguments.delta') {
            $callId = data_get($data, 'item_id');
            $delta = data_get($data, 'delta', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    $currentArgs = $call['arguments'] ?? '';
                    $this->state->updateToolCall($index, ['arguments' => $currentArgs.$delta]);

                    return new ToolCallDeltaEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolId: $call['id'],
                        toolName: $call['name'],
                        delta: $delta,
                        messageId: $this->state->messageId()
                    );
                }
            }
        }

        if ($type === 'response.function_call_arguments.done') {
            $callId = data_get($data, 'item_id');
            $arguments = data_get($data, 'arguments', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    if ($arguments !== '') {
                        $this->state->updateToolCall($index, ['arguments' => $arguments]);
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($this->state->toolCalls());
        $toolResults = [];
        $hasPendingToolCalls = false;
        yield from $this->callToolsAndYieldEventsWithPending($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults, $hasPendingToolCalls);

        if ($hasPendingToolCalls) {
            // Client-executed or approval-required tool calls: end the stream
            // with FinishReason::ToolCalls so the consumer resolves and resumes.
            $this->state->markStepFinished();
            yield from $this->yieldToolCallsFinishEvents($this->state);

            return;
        }

        // Emit step finish after tool calls
        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        $request->addMessage(new AssistantMessage($this->state->currentText(), $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);

            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * Convert raw tool call data to ToolCall objects.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: data_get($toolCall, 'arguments'),
                resultId: data_get($toolCall, 'call_id'),
                reasoningId: data_get($toolCall, 'reasoning_id'),
                reasoningSummary: data_get($toolCall, 'reasoning_summary', []),
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $type = data_get($data, 'type', '');

        if (data_get($data, 'item.type') === 'function_call') {
            return true;
        }

        return in_array($type, [
            'response.function_call_arguments.delta',
            'response.function_call_arguments.done',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningItems(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningItems(array $data, array $reasoningItems): array
    {
        if (data_get($data, 'type') === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning') {
            $index = (int) data_get($data, 'output_index', count($reasoningItems));

            $reasoningItems[$index] = [
                'id' => data_get($data, 'item.id'),
                'summary' => data_get($data, 'item.summary', []),
            ];
        }

        return $reasoningItems;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isToolCallComplete(array $data): bool
    {
        return data_get($data, 'type') === 'response.function_call_arguments.done';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function getCompletedToolCall(array $data): ?ToolCall
    {
        $callId = data_get($data, 'item_id');

        foreach ($this->state->toolCalls() as $call) {
            if (($call['id'] ?? null) === $callId) {
                return new ToolCall(
                    id: $call['id'],
                    name: $call['name'],
                    arguments: $call['arguments'] ?? '',
                    resultId: $call['call_id'] ?? null,
                    reasoningId: $call['reasoning_id'] ?? null,
                    reasoningSummary: $call['reasoning_summary'] ?? []
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningSummaryDelta(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.reasoning_summary_text.delta';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningSummaryDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.reasoning_summary_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractOutputTextDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.output_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge(['stream' => true], $this->buildRequestBody($request)),
            );

        return $response;
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
