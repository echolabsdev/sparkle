<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    /**
     * @param  BatchRequestItem[]|null  $items
     */
    public function create(?array $items = null, ?string $inputFileId = null): BatchJob
    {
        $request = $this->toCreateRequest($items, $inputFileId);

        try {
            return $this->provider->batch($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function retrieve(string $batchId): BatchJob
    {
        $request = $this->toRetrieveRequest($batchId);

        try {
            return $this->provider->retrieveBatch($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function list(?int $limit = null, ?string $afterId = null, ?string $beforeId = null): BatchListResult
    {
        $request = $this->toListRequest($limit, $afterId, $beforeId);

        try {
            return $this->provider->listBatches($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    /**
     * @return BatchResultItem[]
     */
    public function getResults(string $batchId): array
    {
        $request = $this->toGetResultsRequest($batchId);

        try {
            return $this->provider->getBatchResults($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function cancel(string $batchId): BatchJob
    {
        $request = $this->toCancelRequest($batchId);

        try {
            return $this->provider->cancelBatch($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    /**
     * @param  BatchRequestItem[]|null  $items
     */
    public function toCreateRequest(?array $items = null, ?string $inputFileId = null): BatchRequest
    {
        return (new BatchRequest(
            items: $items,
            inputFileId: $inputFileId,
        ))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toRetrieveRequest(string $batchId): RetrieveBatchRequest
    {
        return (new RetrieveBatchRequest(batchId: $batchId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toListRequest(?int $limit = null, ?string $afterId = null, ?string $beforeId = null): ListBatchesRequest
    {
        return (new ListBatchesRequest(
            limit: $limit,
            afterId: $afterId,
            beforeId: $beforeId,
        ))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toGetResultsRequest(string $batchId): GetBatchResultsRequest
    {
        return (new GetBatchResultsRequest(batchId: $batchId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toCancelRequest(string $batchId): CancelBatchRequest
    {
        return (new CancelBatchRequest(batchId: $batchId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }
}
