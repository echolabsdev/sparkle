<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesBatchResponse;

class Retrieve
{
    use HandlesBatchResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(string $batchId): BatchJob
    {
        $response = $this->client->get("messages/batches/{$batchId}");
        $data = $response->json();

        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }
}
