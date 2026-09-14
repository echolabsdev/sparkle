<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Requesty\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\Requesty\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Requesty\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Requesty\Maps\MessageMap;
use Prism\Prism\Streaming\EventID;
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
    use BuildsRequestOptions;
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

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
        if ($depth === 0) {
            $this->state->reset();
        }

        $text = '';
        $toolCalls = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                $this->state
                    ->withMessageId(EventID::generate('msg'))
                    ->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $data['model'] ?? $request->model(),
                    provider: 'requesty'
                );
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                $finishReason = data_get($data, 'choices.0.finish_reason');
                if ($finishReason !== null && $this->mapFinishReason($data) === FinishReason::ToolCalls) {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        $this->state->markTextCompleted();

                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
                        );
                    }

                    if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                        yield new ThinkingCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            reasoningId: $this->state->reasoningId()
                        );
                    }

                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                    return;
                }

                continue;
            }

            $finishReasonValue = data_get($data, 'choices.0.finish_reason');
            if ($finishReasonValue !== null && $this->mapFinishReason($data) === FinishReason::ToolCalls) {
                if ($this->state->hasTextStarted() && $text !== '') {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            if ($this->hasReasoningDelta($data)) {
                $reasoningDelta = $this->extractReasoningDelta($data);

                if ($reasoningDelta !== '') {
                    if ($this->state->shouldEmitThinkingStart()) {
                        $this->state
                            ->withReasoningId(EventID::generate('reasoning'))
                            ->markThinkingStarted();

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

                    continue;
                }
            }

            $content = $this->extractContentDelta($data);
            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId()
                );
            }

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');
            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                if ($this->state->hasTextStarted() && $text !== '') {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                $this->state->withFinishReason($finishReason);
            }

            // Extract usage from any chunk that has it
            // Requesty sends usage in a separate final chunk when stream_options.include_usage=true
            $usage = $this->extractUsage($data);
            if ($usage instanceof Usage) {
                $this->state->addUsage($usage);
            }
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

        if (Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Requesty', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty($data['choices'][0]['delta']['tool_calls']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $deltaToolCalls = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($deltaToolCalls as $deltaToolCall) {
            $index = $deltaToolCall['index'];

            if (isset($deltaToolCall['id'])) {
                $toolCalls[$index]['id'] = $deltaToolCall['id'];
            }

            if (isset($deltaToolCall['type'])) {
                $toolCalls[$index]['type'] = $deltaToolCall['type'];
            }

            if (isset($deltaToolCall['function'])) {
                if (isset($deltaToolCall['function']['name'])) {
                    $toolCalls[$index]['function']['name'] = $deltaToolCall['function']['name'];
                }

                if (isset($deltaToolCall['function']['arguments'])) {
                    $toolCalls[$index]['function']['arguments'] =
                        ($toolCalls[$index]['function']['arguments'] ?? '').
                        $deltaToolCall['function']['arguments'];
                }
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningDelta(array $data): bool
    {
        return isset($data['choices'][0]['delta']['reasoning']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.reasoning', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.content', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'choices.0.finish_reason');

        if ($finishReason === null) {
            return FinishReason::Unknown;
        }

        return $this->mapFinishReason($data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth
    ): Generator {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId()
            );
        }

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

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->state
            ->resetTextState()
            ->withMessageId(EventID::generate('msg'));

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
            ->map(function ($toolCall): ToolCall {
                $arguments = data_get($toolCall, 'function.arguments', '');

                if (is_string($arguments) && $arguments !== '') {
                    try {
                        $parsedArguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                        $arguments = $parsedArguments;
                    } catch (Throwable) {
                        $arguments = ['raw' => $arguments];
                    }
                }

                return new ToolCall(
                    data_get($toolCall, 'id'),
                    data_get($toolCall, 'function.name'),
                    $arguments,
                );
            })
            ->all();
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], $this->buildRequestOptions($request, [
                    'stream_options' => ['include_usage' => true],
                ]))
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if (! $usage) {
            return null;
        }

        return new Usage(
            promptTokens: max(0, (int) data_get($usage, 'prompt_tokens', 0) - (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0)),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0) ?: null,
            thoughtTokens: (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0)
        );
    }
}
