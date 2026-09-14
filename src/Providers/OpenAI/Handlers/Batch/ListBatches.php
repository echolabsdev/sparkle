<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\ListBatchesRequest;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;

/**
 * @see https://developers.openai.com/api/reference/resources/batches/methods/list
 */
class ListBatches
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(ListBatchesRequest $request): BatchListResult
    {
        $query = Arr::whereNotNull([
            'limit' => $request->limit,
            'after' => $request->afterId,
        ]);

        $response = $this->client->get('batches', $query ?: null);
        $data = $response->json();
        $this->handleResponseErrors($data);

        $jobs = array_map(
            self::mapBatchJob(...),
            data_get($data, 'data', [])
        );

        return new BatchListResult(
            data: $jobs,
            hasMore: (bool) data_get($data, 'has_more', false),
            lastId: data_get($data, 'last_id'),
        );
    }
}
