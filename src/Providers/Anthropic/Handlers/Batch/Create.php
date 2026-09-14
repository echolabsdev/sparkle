<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Exceptions\PrismBatchPayloadSizeExceededException;
use Prism\Prism\Exceptions\PrismBatchRequestLimitExceededException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesBatchResponse;
use Prism\Prism\Providers\Anthropic\Handlers\Text;

/**
 * @see https://platform.claude.com/docs/en/api/beta/messages/batches/create
 */
class Create
{
    use HandlesBatchResponse;

    /**
     * Anthropic's batch processing has a maximum of 100,000 requests per batch.
     *
     * @link https://platform.claude.com/docs/en/build-with-claude/batch-processing#batch-limitations
     */
    public const MAX_REQUESTS = 100_000;

    /**
     * Anthropic's batch processing has a maximum payload size of 256MB. This includes the total size of all requests in the batch.
     *
     * @link https://platform.claude.com/docs/en/build-with-claude/batch-processing#batch-limitations
     */
    public const MAX_PAYLOAD_BYTES = 256 * 1024 * 1024; // 256MB

    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * @throws PrismBatchRequestLimitExceededException
     * @throws ConnectionException
     * @throws PrismBatchPayloadSizeExceededException
     * @throws PrismException
     */
    public function handle(BatchRequest $batchRequest): BatchJob
    {
        if ($batchRequest->items === null) {
            throw new PrismException('Anthropic batch requires "items" to be provided.');
        }

        if (count($batchRequest->items) > self::MAX_REQUESTS) {
            throw PrismBatchRequestLimitExceededException::make('Anthropic', count($batchRequest->items), self::MAX_REQUESTS);
        }

        $requests = array_map(static fn (BatchRequestItem $item): array => [
            'custom_id' => $item->customId,
            'params' => Text::buildHttpRequestPayload($item->request),
        ], $batchRequest->items);

        $payload = ['requests' => $requests];

        $maxPayloadBytes = $this->maxPayloadBytes();
        $payloadSize = strlen((string) json_encode($payload));
        if ($payloadSize > $maxPayloadBytes) {
            throw PrismBatchPayloadSizeExceededException::make('Anthropic', $maxPayloadBytes);
        }

        $response = $this->client->post('messages/batches', $payload);
        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }

    /**
     * Overridable via config so the guard can be tested without materializing
     * multi-hundred-megabyte payloads.
     */
    protected function maxPayloadBytes(): int
    {
        return (int) config('prism.anthropic.batch.max_payload_bytes', self::MAX_PAYLOAD_BYTES);
    }
}
