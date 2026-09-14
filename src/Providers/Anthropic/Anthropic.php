<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\CancelBatchRequest;
use Prism\Prism\Batch\GetBatchResultsRequest;
use Prism\Prism\Batch\ListBatchesRequest;
use Prism\Prism\Batch\RetrieveBatchRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Files\DeleteFileRequest;
use Prism\Prism\Files\DeleteFileResult;
use Prism\Prism\Files\DownloadFileRequest;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\FileListResult;
use Prism\Prism\Files\GetFileMetadataRequest;
use Prism\Prism\Files\ListFilesRequest;
use Prism\Prism\Files\UploadFileRequest;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Cancel;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Create;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\ListBatches;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Results;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Retrieve;
use Prism\Prism\Providers\Anthropic\Handlers\Files\Delete as FileDelete;
use Prism\Prism\Providers\Anthropic\Handlers\Files\Download as FileDownload;
use Prism\Prism\Providers\Anthropic\Handlers\Files\GetMetadata as FileGetMetadata;
use Prism\Prism\Providers\Anthropic\Handlers\Files\ListFiles as FileListFiles;
use Prism\Prism\Providers\Anthropic\Handlers\Files\Upload as FileUpload;
use Prism\Prism\Providers\Anthropic\Handlers\Stream;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Anthropic extends Provider
{
    use InitializesClient, ProcessesRateLimits;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $apiVersion,
        public readonly string $url,
        public readonly ?string $betaFeatures = null,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry(),
                betaFeatures: $this->requestBetaFeatures($request)
            ),
            $request
        );

        return $handler->handle();
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry(),
                betaFeatures: $this->requestBetaFeatures($request)
            ),
            $request
        );

        return $handler->handle();
    }

    /**
     * @return Generator<StreamEvent>
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
            betaFeatures: $this->requestBetaFeatures($request)
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function batch(BatchRequest $request): BatchJob
    {
        return (new Create($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function retrieveBatch(RetrieveBatchRequest $request): BatchJob
    {
        return (new Retrieve($this->client($request->clientOptions(), $request->clientRetry())))->handle($request->batchId);
    }

    #[\Override]
    public function listBatches(ListBatchesRequest $request): BatchListResult
    {
        return (new ListBatches($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    /**
     * @return BatchResultItem[]
     */
    #[\Override]
    public function getBatchResults(GetBatchResultsRequest $request): array
    {
        return (new Results($this->client($request->clientOptions(), $request->clientRetry())))->handle($request->batchId);
    }

    #[\Override]
    public function cancelBatch(CancelBatchRequest $request): BatchJob
    {
        return (new Cancel($this->client($request->clientOptions(), $request->clientRetry())))->handle($request->batchId);
    }

    #[\Override]
    public function uploadFile(UploadFileRequest $request): FileData
    {
        return (new FileUpload($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function listFiles(ListFilesRequest $request): FileListResult
    {
        return (new FileListFiles($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function getFileMetadata(GetFileMetadataRequest $request): FileData
    {
        return (new FileGetMetadata($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function deleteFile(DeleteFileRequest $request): DeleteFileResult
    {
        return (new FileDelete($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function downloadFile(DownloadFileRequest $request): string
    {
        return (new FileDownload($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: $e->response->hasHeader('retry-after')
                    ? (int) $e->response->getHeader('retry-after')[0]
                    : null
            ),
            529 => throw PrismProviderOverloadedException::make(ProviderName::Anthropic),
            413 => throw PrismRequestTooLargeException::make(ProviderName::Anthropic),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Anthropic',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null, ?string $betaFeatures = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders(array_filter([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'anthropic-beta' => $betaFeatures ?? $this->betaFeatures,
            ]))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }

    /**
     * Merge config-level beta features with per-request ones supplied via
     * withProviderOptions(['anthropic_beta' => 'skills-2025-10-02']) — a
     * comma-separated string or an array of feature flags.
     */
    protected function requestBetaFeatures(TextRequest|StructuredRequest $request): ?string
    {
        $requested = $request->providerOptions('anthropic_beta');

        $merged = collect([$this->betaFeatures])
            ->merge(is_array($requested) ? $requested : [$requested])
            ->flatMap(fn (mixed $value): array => is_string($value) ? array_map(trim(...), explode(',', $value)) : [])
            ->filter()
            ->unique()
            ->implode(',');

        return $merged === '' ? null : $merged;
    }
}
