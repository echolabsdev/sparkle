<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Qwen\Maps\FinishReasonMap;
use Prism\Prism\Providers\Qwen\Maps\MessageMap;
use Prism\Prism\Providers\Qwen\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Qwen\Maps\ToolMap;
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
    use CallsTools;

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
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

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
                $this->state->withMessageId(EventID::generate())->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: 'qwen'
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

                $rawFinishReason = data_get($data, 'output.choices.0.finish_reason');
                if ($rawFinishReason === 'tool_calls') {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        $this->state->markTextCompleted();

                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
                        );
                    }

                    if ($this->state->hasThinkingStarted()) {
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

            $reasoningDelta = $this->extractReasoningDelta($data);
            if ($reasoningDelta !== '') {
                if ($this->state->shouldEmitThinkingStart()) {
                    $this->state->withReasoningId(EventID::generate())->markThinkingStarted();

                    yield new ThinkingStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                yield new ThinkingEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $reasoningDelta,
                    reasoningId: $this->state->reasoningId()
                );

                continue;
            }

            if ($this->state->hasThinkingStarted()) {
                yield new ThinkingCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $this->state->reasoningId()
                );
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

                continue;
            }

            $rawFinishReason = data_get($data, 'output.choices.0.finish_reason');
            if ($rawFinishReason !== null && $rawFinishReason !== 'null') {
                $finishReason = FinishReasonMap::map($rawFinishReason);

                if ($this->state->hasTextStarted() && $text !== '') {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                if ($this->state->hasThinkingStarted()) {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                $this->state->withFinishReason($finishReason);

                $usage = $this->extractUsage($data);
                if ($usage instanceof Usage) {
                    $this->state->addUsage($usage);
                }
            }
        }

        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

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
            usage: $this->state->usage()
        );
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismStreamDecodeException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        // DashScope SSE format has id:, event:, :HTTP_STATUS/ lines before data:
        // Skip all non-data lines
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data:')));

        if ($line === '') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Qwen', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty(data_get($data, 'output.choices.0.message.tool_calls', []));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $messageToolCalls = data_get($data, 'output.choices.0.message.tool_calls', []);

        foreach ($messageToolCalls as $toolCall) {
            $index = data_get($toolCall, 'index', 0);

            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => '',
                    'name' => '',
                    'arguments' => '',
                ];
            }

            if ($id = data_get($toolCall, 'id')) {
                $toolCalls[$index]['id'] = $id;
            }

            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
            }

            $arguments = data_get($toolCall, 'function.arguments', '');
            if ($arguments !== '') {
                $toolCalls[$index]['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningDelta(array $data): string
    {
        return data_get($data, 'output.choices.0.message.reasoning_content') ?? '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        return data_get($data, 'output.choices.0.message.content') ?? '';
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
            promptTokens: max(0, (int) data_get($usage, 'input_tokens', 0) - (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0)),
            completionTokens: (int) data_get($usage, 'output_tokens', 0),
            cacheReadInputTokens: (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0) ?: null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, string $text, array $toolCalls, int $depth): Generator
    {
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

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        $this->state->resetTextState();
        $this->state->withMessageId(EventID::generate());

        $depth++;
        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'name'),
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
    }

    /**
     * @throws ConnectionException
     */
    protected function sendRequest(Request $request): Response
    {
        $messageMap = new MessageMap($request->messages(), $request->systemPrompts());

        $input = [
            'messages' => $messageMap(),
        ];

        $parameters = Arr::whereNotNull([
            'result_format' => 'message',
            'incremental_output' => true,
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ToolMap::map($request->tools()) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]);

        $payload = [
            'model' => $request->model(),
            'input' => $input,
        ];

        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        // VL (multimodal) models use the multimodal-generation endpoint
        $endpoint = $messageMap->hasImages()
            ? 'services/aigc/multimodal-generation/generation'
            : 'services/aigc/text-generation/generation';

        /** @var Response $response */
        $response = $this->client->withOptions(['stream' => true])->post($endpoint, $payload);

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
