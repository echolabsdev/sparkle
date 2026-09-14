<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Replicate\Handlers\Audio;
use Prism\Prism\Providers\Replicate\Handlers\Embeddings;
use Prism\Prism\Providers\Replicate\Handlers\Images;
use Prism\Prism\Providers\Replicate\Handlers\Stream;
use Prism\Prism\Providers\Replicate\Handlers\Structured as StructuredHandler;
use Prism\Prism\Providers\Replicate\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Replicate extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        public readonly ?string $webhookUrl = null,
        public readonly bool $useSyncMode = true,
        public readonly int $pollingInterval = 1000,
        public readonly int $maxWaitTime = 60,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        $handler = new Audio(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handleTextToSpeech($request);
    }

    #[\Override]
    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        $handler = new Audio(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handleSpeechToText($request);
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new StructuredHandler(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make([]),
            529 => throw PrismProviderOverloadedException::make(ProviderName::Replicate),
            413 => throw PrismRequestTooLargeException::make(ProviderName::Replicate),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withToken($this->apiKey)
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
