<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Z\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Z\Maps\MessageMap;
use Prism\Prism\Providers\Z\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Z\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
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

class Stream
{
    use CallsTools, MapsFinishReason;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

    /**
     * @throws PrismStreamDecodeException
     * @throws PrismException
     * @throws ConnectionException
     */
    public function handle(Request $request): Generator
    {
        yield from $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @throws PrismStreamDecodeException
     * @throws PrismException
     * @throws ConnectionException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        $this->state->reset()->withMessageId(EventID::generate());

        $text = '';
        $toolCalls = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: Provider::Z->value,
                );

                $this->state->markStreamStarted();

                continue;
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                $rawFinishReason = data_get($data, 'choices.0.finish_reason');

                if ($rawFinishReason === 'tool_calls') {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        $this->state->markTextCompleted();

                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId(),
                        );
                    }

                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                    return;
                }

                continue;
            }

            $content = $this->extractContentDelta($data);

            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId(),
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId(),
                );

                continue;
            }

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');

            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                if ($this->state->hasTextStarted() && $text !== '') {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId(),
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
            timestamp: time(),
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
     * @return null|array<string, mixed>
     *
     * @throws PrismStreamDecodeException
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
        } catch (JsonException $e) {
            throw new PrismStreamDecodeException(Provider::Z->name, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty(data_get($data, 'choices.0.delta.tool_calls', []));
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
            $index = data_get($deltaToolCall, 'index', 0);

            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => '',
                    'name' => '',
                    'arguments' => '',
                ];
            }

            if ($id = data_get($deltaToolCall, 'id')) {
                $toolCalls[$index]['id'] = $id;
            }

            if ($name = data_get($deltaToolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
            }

            if ($arguments = data_get($deltaToolCall, 'function.arguments')) {
                $toolCalls[$index]['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        $delta = data_get($data, 'choices.0.delta', []);

        if (is_array($delta)) {
            $content = data_get($delta, 'content', '');

            return is_string($content) ? $content : '';
        }

        return '';
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

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     *
     * @throws PrismException
     * @throws PrismStreamDecodeException
     * @throws ConnectionException
     */
    protected function handleToolCalls(Request $request, string $text, array $toolCalls, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId(),
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
            timestamp: time(),
        );

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

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
        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    'max_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()) ?: null,
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                ]))
            );
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
