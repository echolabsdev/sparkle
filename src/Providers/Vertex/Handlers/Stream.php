<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
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

    protected ?string $currentThoughtSignature = null;

    public function __construct(
        protected PendingRequest $client,
        protected string $model,
    ) {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        yield from $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());

        $this->state->reset();
        $this->currentThoughtSignature = null;
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

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                $this->state->withMessageId(EventID::generate());

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: data_get($data, 'modelVersion', 'unknown'),
                    provider: 'vertex'
                );
                $this->state->markStreamStarted();
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            $this->state->withUsage($this->extractUsage($data));

            if ($this->hasToolCalls($data)) {
                $existingIndices = array_keys($this->state->toolCalls());

                $toolCalls = $this->extractToolCalls($data, $this->state->toolCalls());
                foreach ($toolCalls as $index => $toolCall) {
                    $this->state->addToolCall($index, $toolCall);
                }

                foreach ($this->state->toolCalls() as $index => $toolCallData) {
                    if (! in_array($index, $existingIndices, true)) {
                        yield new ToolCallEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolCall: $this->mapToolCall($toolCallData),
                            messageId: $this->state->messageId()
                        );
                    }
                }

                if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                    yield from $this->handleToolCalls($request, $depth, $data);

                    return;
                }

                continue;
            }

            $parts = data_get($data, 'candidates.0.content.parts', []);

            foreach ($parts as $part) {
                if (isset($part['thought']) && $part['thought'] === true) {
                    $thinkingContent = $part['text'] ?? '';

                    if ($thinkingContent !== '') {
                        if ($this->state->reasoningId() === '') {
                            $this->state->withReasoningId(EventID::generate());

                            yield new ThinkingStartEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                reasoningId: $this->state->reasoningId()
                            );
                        }

                        $this->state->appendThinking($thinkingContent);

                        yield new ThinkingEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $thinkingContent,
                            reasoningId: $this->state->reasoningId()
                        );
                    }
                } elseif (isset($part['text']) && (! isset($part['thought']) || $part['thought'] === false)) {
                    $content = $part['text'];

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
                }
            }

            $finishReason = $this->mapFinishReason($data);

            if ($finishReason !== FinishReason::Unknown) {
                if ($this->state->reasoningId() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                if ($this->state->hasTextStarted()) {
                    $this->state->markTextCompleted();

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                $this->state->withFinishReason($finishReason);
                $this->state->withMetadata([
                    'grounding_metadata' => $this->extractGroundingMetadata($data),
                ]);
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
            usage: $this->state->usage(),
            additionalContent: Arr::whereNotNull([
                'grounding_metadata' => $this->state->metadata()['grounding_metadata'] ?? null,
                'thoughtSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
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

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Vertex', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);
        $nextIndex = $toolCalls === [] ? 0 : max(array_keys($toolCalls)) + 1;

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                if (isset($part['thoughtSignature'])) {
                    $this->currentThoughtSignature = $part['thoughtSignature'];
                }

                $toolCalls[$nextIndex] = [
                    'id' => EventID::generate('vx'),
                    'name' => data_get($part, 'functionCall.name'),
                    'arguments' => data_get($part, 'functionCall.args', []),
                    'reasoningId' => $part['thoughtSignature'] ?? $this->currentThoughtSignature,
                ];
                $nextIndex++;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        int $depth,
        array $data = []
    ): Generator {
        $mappedToolCalls = [];

        foreach ($this->state->toolCalls() as $toolCallData) {
            $mappedToolCalls[] = $this->mapToolCall($toolCallData);
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

        if ($toolResults !== []) {
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
                $previousUsage = $this->state->usage();
                $this->state->reset();
                $this->currentThoughtSignature = null;
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);

                if ($previousUsage instanceof Usage && $this->state->usage() instanceof Usage) {
                    $this->state->withUsage(new Usage(
                        promptTokens: $previousUsage->promptTokens + $this->state->usage()->promptTokens,
                        completionTokens: $previousUsage->completionTokens + $this->state->usage()->completionTokens,
                        cacheWriteInputTokens: ($previousUsage->cacheWriteInputTokens ?? 0) + ($this->state->usage()->cacheWriteInputTokens ?? 0),
                        cacheReadInputTokens: ($previousUsage->cacheReadInputTokens ?? 0) + ($this->state->usage()->cacheReadInputTokens ?? 0),
                        thoughtTokens: ($previousUsage->thoughtTokens ?? 0) + ($this->state->usage()->thoughtTokens ?? 0)
                    ));
                }
            } else {
                yield $this->emitStreamEndEvent();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $toolCallData
     */
    protected function mapToolCall(array $toolCallData): ToolCall
    {
        $arguments = data_get($toolCallData, 'arguments', []);

        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            $arguments = json_last_error() === JSON_ERROR_NONE ? $decoded : ['input' => $arguments];
        }

        return new ToolCall(
            id: empty($toolCallData['id']) ? EventID::generate('vx') : $toolCallData['id'],
            name: data_get($toolCallData, 'name', 'unknown'),
            arguments: $arguments,
            reasoningId: data_get($toolCallData, 'reasoningId')
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            promptTokens: max(0, (int) data_get($data, 'usageMetadata.promptTokenCount', 0) - (int) data_get($data, 'usageMetadata.cachedContentTokenCount', 0)),
            completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
            thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'candidates.0.finishReason');

        if (! $finishReason) {
            return FinishReason::Unknown;
        }

        $isToolCall = $this->hasToolCalls($data);

        return FinishReasonMap::map($finishReason, $isToolCall);
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = array_map(
                fn ($providerTool): array => [
                    $providerTool->type => $providerTool->optionsAsObject(),
                ],
                $request->providerTools()
            );
        } elseif ($providerOptions['searchGrounding'] ?? false) {
            $tools = [
                [
                    'google_search' => (object) [],
                ],
            ];
        }

        if ($request->tools() !== []) {
            $tools[] = ['function_declarations' => ToolMap::map($request->tools())];
        }

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = [
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => true,
            ];
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = [
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => true,
            ];
        }

        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post(
                "{$this->model}:streamGenerateContent?alt=sse",
                Arr::whereNotNull([
                    ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'generationConfig' => Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'topP' => $request->topP(),
                        'maxOutputTokens' => $request->maxTokens(),
                        'thinkingConfig' => $thinkingConfig,
                    ]) ?: null,
                    'tools' => $tools !== [] ? $tools : null,
                    'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                    'safetySettings' => $providerOptions['safetySettings'] ?? null,
                ])
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
     * @return array<string, mixed>|null
     */
    protected function extractGroundingMetadata(array $data): ?array
    {
        $groundingMetadata = data_get($data, 'candidates.0.groundingMetadata');

        if (! $groundingMetadata) {
            return null;
        }

        return $groundingMetadata;
    }
}
