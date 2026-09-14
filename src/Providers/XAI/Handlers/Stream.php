<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\XAI\Concerns\ExtractsThinking;
use Prism\Prism\Providers\XAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\XAI\Concerns\ValidatesResponses;
use Prism\Prism\Providers\XAI\Maps\MessageMap;
use Prism\Prism\Providers\XAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\XAI\Maps\ToolMap;
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
    use CallsTools, ExtractsThinking, MapsFinishReason, ValidatesResponses;

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
        $finishReason = null;
        $usage = null;

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                $this->state
                    ->withMessageId(EventID::generate())
                    ->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: data_get($data, 'model', $request->model()),
                    provider: 'xai'
                );
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            $thinkingContent = $this->extractThinking($data, $request);

            if ($thinkingContent !== '') {
                if ($this->state->shouldEmitThinkingStart()) {
                    $this->state
                        ->withReasoningId(EventID::generate())
                        ->markThinkingStarted();

                    yield new ThinkingStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                yield new ThinkingEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $thinkingContent,
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
                $this->state->resetTextState();
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            $content = $this->extractContent($data);

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
                $finishReason = $this->extractFinishReason($data);
                if ($finishReason instanceof FinishReason) {
                    $this->state->withFinishReason($finishReason);
                }

                $usage = $this->extractUsage($data);
                if ($usage instanceof Usage) {
                    $this->state->addUsage($usage);
                }
            }
        }

        if ($toolCalls !== []) {
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

        if ($this->state->hasTextStarted() && $text !== '') {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            );
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
            throw new PrismStreamDecodeException('XAI', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isInitialChunk(array $data): bool
    {
        return isset($data['id']) && isset($data['model']) && data_get($data, 'choices.0.delta.content') === null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty(data_get($data, 'choices.0.delta.tool_calls'));
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
    protected function extractContent(array $data): string
    {
        return data_get($data, 'choices.0.delta.content', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractFinishReason(array $data): ?FinishReason
    {
        $finishReason = data_get($data, 'choices.0.finish_reason');

        if ($finishReason === null) {
            return null;
        }

        return $this->mapFinishReason(['choices' => [['finish_reason' => $finishReason]]]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if ($usage === null) {
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
            ->withMessageId(EventID::generate());

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

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'stream' => true,
                    'max_tokens' => $request->maxTokens() ?? 2048,
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
}
