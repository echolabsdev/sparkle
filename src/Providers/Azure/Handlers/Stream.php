<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Azure\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Azure\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Azure\Maps\MessageMap;
use Prism\Prism\Providers\Azure\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Azure\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
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
    use CallsTools, MapsFinishReason, ValidatesResponses;

    protected StreamState $state;

    public function __construct(
        protected PendingRequest $client,
        protected Azure $provider,
    ) {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        if ($this->provider->usesV1ForModel($request->model())) {
            yield from $this->processV1NativeStream($request);

            return;
        }

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processV1NativeStream(Request $request, int $depth = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        if ($depth === 0) {
            $this->state->reset();
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'api-key: '.$this->provider->apiKey,
                    'Accept: text/event-stream',
                ]),
                'content' => json_encode($this->buildRequestPayload($request, true), JSON_UNESCAPED_SLASHES),
                'ignore_errors' => true,
                'timeout' => 300,
            ],
        ]);

        $url = $this->v1ChatCompletionsUrl();
        $stream = fopen($url, 'r', false, $context);

        if (! is_resource($stream)) {
            yield new ErrorEvent(
                id: EventID::generate(),
                timestamp: time(),
                errorType: 'azure_v1_stream_open_failed',
                message: 'Failed to open Azure v1 stream connection.',
                recoverable: true
            );

            yield from $this->emitFallbackNonStreamResponse($request);

            return;
        }

        stream_set_blocking($stream, true);

        $text = '';
        $toolCalls = [];
        $dataChunkCount = 0;

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->withMessageId(EventID::generate())->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $request->model(),
                provider: 'azure'
            );
        }

        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                usleep(15_000);

                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = ltrim(substr($line, strlen('data:')));
            if ($payload === '') {
                continue;
            }
            if (Str::contains($payload, '[DONE]')) {
                continue;
            }

            $data = json_decode($payload, true);
            if (! is_array($data)) {
                continue;
            }

            $dataChunkCount++;

            if ($this->hasError($data)) {
                yield from $this->handleErrors($data, $request);

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                $rawFinishReason = data_get($data, 'choices.0.finish_reason');
                if ($rawFinishReason === 'tool_calls') {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
                        );
                    }

                    fclose($stream);
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

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');
            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);
                $this->state->withFinishReason($finishReason);

                $usage = $this->extractUsage($data);
                if ($usage instanceof Usage) {
                    $this->state->addUsage($usage);
                }
            }
        }

        fclose($stream);

        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

            return;
        }

        if ($dataChunkCount === 0) {
            yield from $this->emitFallbackNonStreamResponse($request);

            return;
        }

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage()
        );
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0, int $streamAttempt = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        if ($depth === 0) {
            $this->state->reset();
        }

        $text = '';
        $toolCalls = [];
        $dataChunkCount = 0;

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->withMessageId(EventID::generate())->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $request->model(),
                provider: 'azure'
            );
        }

        while (true) {
            $data = $this->parseNextDataLine($body);

            if ($data === null) {
                if ($body->eof()) {
                    break;
                }

                continue;
            }

            $dataChunkCount++;

            if ($this->hasError($data)) {
                yield from $this->handleErrors($data, $request);

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                $rawFinishReason = data_get($data, 'choices.0.finish_reason');
                if ($rawFinishReason === 'tool_calls') {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
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

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');
            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                if ($finishReason === FinishReason::Length && $text === '') {
                    yield new ErrorEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        errorType: 'length_exhausted',
                        message: 'Azure Error: No output text was produced before the token limit was reached. Lower reasoning effort or increase max completion tokens.',
                        recoverable: true
                    );
                }

                if ($this->state->hasTextStarted() && $text !== '') {
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

        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

            return;
        }

        if ($dataChunkCount === 0 && $streamAttempt < 1 && ! $this->provider->usesV1ForModel($request->model())) {
            $retryResponse = $this->sendRequest($request);
            yield from $this->processStream($retryResponse, $request, $depth, $streamAttempt + 1);

            return;
        }

        if ($dataChunkCount === 0) {
            yield from $this->emitFallbackNonStreamResponse($request);

            return;
        }

        yield new StreamEndEvent(
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

        $line = trim($line);

        if ($line === '' || ! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = ltrim(substr($line, strlen('data:')));

        if (Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Azure', $e);
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
        return data_get($data, 'choices.0.delta.content') ?? '';
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
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return data_get($data, 'error') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleErrors(array $data, Request $request): Generator
    {
        $error = data_get($data, 'error', []);
        $type = data_get($error, 'type', data_get($error, 'code', 'unknown_error'));
        $message = data_get($error, 'message', 'No error message provided');

        if ($type === 'rate_limit_exceeded' || $type === '429') {
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

        $toolResults = $this->callTools($request->tools(), $mappedToolCalls);

        foreach ($toolResults as $result) {
            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $result,
                messageId: $this->state->messageId()
            );

            foreach ($result->artifacts as $artifact) {
                yield new ArtifactEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    artifact: $artifact,
                    toolCallId: $result->toolCallId,
                    toolName: $result->toolName,
                    messageId: $this->state->messageId(),
                );
            }
        }

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));

        $this->state->resetTextState();
        $this->state->withMessageId(EventID::generate());

        if ($this->provider->usesV1ForModel($request->model())) {
            yield from $this->processV1NativeStream($request, $depth + 1);

            return;
        }

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1, 0);
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
        /** @var Response $response */
        $response = $this->client
            ->withOptions([
                'stream' => true,
            ])
            ->post(
                'chat/completions',
                $this->buildRequestPayload($request, true)
            );

        if ($response->failed()) {
            $exception = $response->toException();

            if ($exception instanceof RequestException) {
                $this->provider->handleRequestException($request->model(), $exception);
            }
        }

        return $response;
    }

    protected function sendFallbackRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client
            ->withOptions([
                'stream' => false,
            ])
            ->post(
                'chat/completions',
                $this->buildRequestPayload($request, false)
            );

        if ($response->failed()) {
            $exception = $response->toException();

            if ($exception instanceof RequestException) {
                $this->provider->handleRequestException($request->model(), $exception);
            }
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestPayload(Request $request, bool $stream): array
    {
        $tokenParameter = $this->tokenParameter($request->model());

        return array_merge([
            'stream' => $stream ? true : null,
            'stream_options' => $stream ? ['include_usage' => true] : null,
            'model' => $this->provider->usesV1ForModel($request->model())
                ? $this->provider->resolveModelIdentifier($request->model())
                : null,
            'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            $tokenParameter => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ToolMap::map($request->tools()) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'reasoning_effort' => $request->providerOptions('reasoning_effort')
                ?? $request->providerOptions('reasoning.effort'),
            'verbosity' => $request->providerOptions('verbosity')
                ?? $request->providerOptions('text_verbosity'),
        ]));
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function emitFallbackNonStreamResponse(Request $request): Generator
    {
        $response = $this->sendFallbackRequest($request);
        $payload = $response->json();

        if (! is_array($payload)) {
            yield new ErrorEvent(
                id: EventID::generate(),
                timestamp: time(),
                errorType: 'empty_fallback_response',
                message: 'Azure returned an invalid fallback response payload.',
                recoverable: false
            );

            yield new StreamEndEvent(
                id: EventID::generate(),
                timestamp: time(),
                finishReason: FinishReason::Stop,
                usage: $this->state->usage()
            );

            return;
        }

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->withMessageId(EventID::generate())->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $request->model(),
                provider: 'azure'
            );
        }

        $content = $this->extractFallbackContent($payload);
        if ($content !== '') {
            if ($this->state->shouldEmitTextStart()) {
                $this->state->markTextStarted();

                yield new TextStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId()
                );
            }

            $chunks = $this->chunkFallbackText($content);
            foreach ($chunks as $chunk) {
                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $chunk,
                    messageId: $this->state->messageId()
                );

                // Emit chunks progressively so clients render streaming text.
                usleep(12_000);
            }

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            );
        }

        $finishReason = $this->mapFinishReason($payload);
        $this->state->withFinishReason($finishReason);

        $usage = $this->extractUsage($payload);
        if ($usage instanceof Usage) {
            $this->state->addUsage($usage);
        }

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage()
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractFallbackContent(array $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');

        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = data_get($item, 'text');
            if (is_string($text) && $text !== '') {
                $parts[] = $text;
            }
        }

        return implode('', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected function chunkFallbackText(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $length = mb_strlen($normalized);
        $chunkSize = 80;
        $chunks = [];

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunks[] = mb_substr($normalized, $offset, $chunkSize);
        }

        return $chunks === [] ? [$normalized] : $chunks;
    }

    protected function v1ChatCompletionsUrl(): string
    {
        $base = rtrim($this->provider->url, '/');

        if (str_contains($base, '/chat/completions')) {
            return $base;
        }

        if (str_contains($base, '/openai/v1')) {
            return $base.'/chat/completions';
        }

        return $base.'/openai/v1/chat/completions';
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';
        $idleReads = 0;
        $maxIdleReads = 150; // up to ~1.5 seconds waiting for the next chunk

        while (true) {
            $byte = $stream->read(1);

            if ($byte === '') {
                if ($stream->eof()) {
                    if ($buffer !== '') {
                        break;
                    }

                    if ($idleReads >= $maxIdleReads) {
                        break;
                    }
                }

                $idleReads++;
                usleep(10_000);

                continue;
            }

            $idleReads = 0;
            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    protected function tokenParameter(string $model): string
    {
        return str_contains(mb_strtolower($model), 'gpt-5')
            ? 'max_completion_tokens'
            : 'max_tokens';
    }
}
