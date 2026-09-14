<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenRouter\Concerns\ExtractsErrorDetails;
use Prism\Prism\Providers\OpenRouter\Handlers\Embeddings;
use Prism\Prism\Providers\OpenRouter\Handlers\Stream;
use Prism\Prism\Providers\OpenRouter\Handlers\Structured;
use Prism\Prism\Providers\OpenRouter\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class OpenRouter extends Provider
{
    use ExtractsErrorDetails;
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        public readonly ?string $httpReferer = null,
        public readonly ?string $xTitle = null,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $statusCode = $e->response->getStatusCode();
        $responseBody = (string) $e->response->body();
        $responseData = $e->response->json();

        $errorData = data_get($responseData, 'error', []);
        $details = $this->extractErrorDetails(is_array($errorData) ? $errorData : []);
        $errorMessage = $details['message'];
        $providerLabel = $this->formatProviderLabel($details['providerName']);

        match ($statusCode) {
            400 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Bad Request%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            401 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Authentication Error%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            402 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Insufficient Credits%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            403 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Moderation Error%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            408 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Request Timeout%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            413 => throw PrismRequestTooLargeException::make(ProviderName::OpenRouter),
            429 => throw PrismRateLimitedException::make(
                rateLimits: [],
                retryAfter: $e->response->hasHeader('retry-after')
                    ? (int) $e->response->header('retry-after')
                    : null
            ),
            502 => throw PrismException::providerResponseError(
                sprintf('OpenRouter Model Error%s: %s', $providerLabel, $errorMessage),
                $statusCode,
                $responseBody,
            ),
            503 => throw PrismProviderOverloadedException::make(ProviderName::OpenRouter),
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
            ->withHeaders(array_filter([
                'HTTP-Referer' => $this->httpReferer,
                'X-Title' => $this->xTitle,
            ]))
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
