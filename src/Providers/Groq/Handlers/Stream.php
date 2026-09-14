<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Groq\Concerns\ValidateResponse;
use Prism\Prism\Providers\Groq\Maps\FinishReasonMap;
use Prism\Prism\Providers\Groq\Maps\MessageMap;
use Prism\Prism\Providers\Groq\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Groq\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
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
    use CallsTools, ProcessRateLimits, ValidateResponse;

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

        // Only reset state on the first call (depth 0)
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

            // Emit stream start event if not already started
            if ($this->state->shouldEmitStreamStart()) {
                $this->state->withMessageId(EventID::generate())->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: 'groq'
                );
            }

            // Emit step start event once per step
            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            if ($this->hasError($data)) {
                yield from $this->handleErrors($data, $request);

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                // Complete any ongoing text
                if ($this->state->hasTextStarted() && $text !== '') {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';

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

            // Only emit completion events when we actually have a finish reason (not null)
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

                $this->state->withFinishReason($finishReason);

                $usage = $this->extractUsage($data);
                if ($usage instanceof Usage) {
                    $this->state->addUsage($usage);
                }
            }
        }

        // Emit step finish before stream end
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
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Groq', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'choices.0.delta.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            $arguments = data_get($toolCall, 'function.arguments');

            if (! is_null($arguments)) {
                if (! isset($toolCalls[$index]['arguments'])) {
                    $toolCalls[$index]['arguments'] = '';
                }
                $toolCalls[$index]['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
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

        // Emit tool call events
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

        // Emit step finish after tool calls
        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        // Reset text state for next response
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
     * Convert raw tool call data to ToolCall objects.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(function ($toolCall): ToolCall {
                $arguments = data_get($toolCall, 'arguments', '');

                // Parse JSON arguments if needed
                if (is_string($arguments) && $arguments !== '') {
                    try {
                        $parsedArguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                        $arguments = $parsedArguments;
                    } catch (Throwable) {
                        // If JSON parsing fails, use the raw string
                        $arguments = ['raw' => $arguments];
                    }
                }

                return new ToolCall(
                    data_get($toolCall, 'id'),
                    data_get($toolCall, 'name'),
                    $arguments,
                );
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'choices.0.delta.tool_calls');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return data_get($data, 'error') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'choices.0.finish_reason'));
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
        );
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            /** @var Response $response */
            $response = $this
                ->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post('chat/completions',
                    array_merge([
                        'stream' => true,
                        'model' => $request->model(),
                        'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                        'max_tokens' => $request->maxTokens(),
                    ], Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                        'tools' => ToolMap::map($request->tools()),
                        'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    ]))
                );

            return $response;
        } catch (RequestException $e) {
            if ($e->response->getStatusCode() === 429) {
                throw new PrismRateLimitedException(
                    $this->processRateLimits($e->response),
                    (int) $e->response->header('retry-after')
                );
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
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
     * @return Generator<StreamEvent>
     */
    protected function handleErrors(array $data, Request $request): Generator
    {
        $error = data_get($data, 'error', []);
        $type = data_get($error, 'type', 'unknown_error');
        $message = data_get($error, 'message', 'No error message provided');

        if ($type === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        yield new ErrorEvent(
            id: EventID::generate(),
            timestamp: time(),
            errorType: $type,
            message: $message,
            recoverable: false
        );
    }
}
