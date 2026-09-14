<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
use Prism\Prism\Providers\Ollama\ValueObjects\OllamaStreamState;
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
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, MapsFinishReason;

    protected OllamaStreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new OllamaStreamState;
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

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            // Emit stream start event if not already started
            if ($this->state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: 'ollama'
                );
                $this->state->markStreamStarted()->withMessageId(EventID::generate());
            }

            // Emit step start event once per step
            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            // Accumulate token counts
            $this->state->addPromptTokens((int) data_get($data, 'prompt_eval_count', 0));
            $this->state->addCompletionTokens((int) data_get($data, 'eval_count', 0));

            // Handle thinking content first
            $thinking = data_get($data, 'message.thinking', '');
            if ($thinking !== '') {
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
                    delta: $thinking,
                    reasoningId: $this->state->reasoningId()
                );

                continue;
            }

            // If we were emitting thinking and it's now stopped, mark it complete
            if ($this->state->hasThinkingStarted()) {
                $this->state->markThinkingCompleted();
                yield new ThinkingCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $this->state->reasoningId()
                );
                // Don't continue here - we want to process the rest of this data chunk
            }

            // Accumulate tool calls if present (don't emit events yet)
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $this->state->toolCalls());
                foreach ($toolCalls as $index => $toolCall) {
                    $this->state->addToolCall($index, $toolCall);
                }
            }

            // Handle text content
            $content = data_get($data, 'message.content', '');
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

            // Handle tool call completion when stream is done (like original)
            if ((bool) data_get($data, 'done', false) && $this->state->hasToolCalls()) {
                // Emit text complete if we had text content
                if ($this->state->hasTextStarted()) {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                yield from $this->handleToolCalls($request, $text, $depth);

                return;
            }

            // Handle regular completion (no tool calls)
            if ((bool) data_get($data, 'done', false)) {
                // Emit text complete if we had text content
                if ($this->state->hasTextStarted()) {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                // Emit step finish before stream end
                $this->state->markStepFinished();
                yield new StepFinishEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );

                // Emit stream end event with usage
                yield $this->emitStreamEndEvent();

                return;
            }
        }
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::Stop,
            usage: new Usage(
                promptTokens: $this->state->promptTokens(),
                completionTokens: $this->state->completionTokens()
            )
        );
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (in_array(trim($line), ['', '0'], true)) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Ollama', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'message.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            if ($arguments = data_get($toolCall, 'function.arguments')) {

                $argumentValue = is_array($arguments) ? json_encode($arguments) : $arguments;
                $toolCalls[$index]['arguments'] .= $argumentValue;
            }
        }

        return $toolCalls;
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        int $depth
    ): Generator {
        $mappedToolCalls = $this->mapToolCalls($this->state->toolCalls());

        // Emit tool call events for each completed tool call
        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId()
            );
        }

        // Execute tools and emit results
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

        // Add messages for next turn
        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        // Continue streaming if within step limit
        $depth++;
        if ($depth < $request->maxSteps()) {
            $this->state->reset();
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'message.tool_calls');
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('api/chat', [
                'model' => $request->model(),
                'messages' => (new MessageMap(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )))->map(),
                'tools' => ToolMap::map($request->tools()),
                'stream' => true,
                ...Arr::whereNotNull([
                    'think' => $request->providerOptions('thinking') ?? (
                        $request->reasoningEnabled() === false ? false : null
                    ),
                    'keep_alive' => $request->providerOptions('keep_alive'),
                ]),
                'options' => Arr::whereNotNull(array_merge([
                    'temperature' => $request->temperature(),
                    'num_predict' => $request->maxTokens() ?? 2048,
                    'top_p' => $request->topP(),
                ], $request->providerOptions())),
            ]);

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
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'name') ?? '',
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
    }
}
