<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Qwen\Handlers\Embeddings;
use Prism\Prism\Providers\Qwen\Handlers\Images;
use Prism\Prism\Providers\Qwen\Handlers\Stream;
use Prism\Prism\Providers\Qwen\Handlers\Structured;
use Prism\Prism\Providers\Qwen\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Qwen extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
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
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images($this->client(
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
            $request->clientRetry(),
            ['X-DashScope-SSE' => 'enable']
        ));

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $statusCode = $e->response->getStatusCode();
        $data = $e->response->json() ?? [];
        $errorCode = data_get($data, 'code', '');

        match (true) {
            $errorCode === 'Arrearage' => throw PrismException::providerResponseError(
                sprintf('Qwen Account Arrearage: %s', data_get($data, 'message', 'Unknown error'))
            ),
            $errorCode === 'DataInspectionFailed' || $errorCode === 'data_inspection_failed' => throw PrismException::providerResponseError(
                sprintf('Qwen Content Moderation Failed: %s', data_get($data, 'message', 'Unknown error'))
            ),
            $statusCode === 429 => throw PrismRateLimitedException::make([]),
            $statusCode === 503 => throw PrismProviderOverloadedException::make('Qwen'),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        $errorCode = data_get($data, 'code');

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Qwen',
            statusCode: $e->response->getStatusCode(),
            errorType: $errorCode !== '' ? $errorCode : null,
            errorMessage: data_get($data, 'message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     * @param  array<string, string>  $headers
     */
    protected function client(array $options = [], array $retry = [], array $headers = []): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->when($headers !== [], fn ($client) => $client->withHeaders($headers))
            ->baseUrl($this->url);
    }
}
