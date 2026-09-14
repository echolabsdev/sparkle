<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchJobRequestCounts;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;

trait HandlesBatchResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function handleResponseErrors(?array $data): void
    {
        if ($data && data_get($data, 'error')) {
            $message = data_get($data, 'error.message');
            $message = is_array($message) ? implode(', ', $message) : $message;

            throw PrismException::providerResponseError(vsprintf(
                'OpenAI Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    $message,
                ]
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function mapBatchJob(array $data): BatchJob
    {
        return new BatchJob(
            id: data_get($data, 'id', ''),
            status: self::mapStatus(data_get($data, 'status', '')),
            requestCounts: new BatchJobRequestCounts(
                processing: (int) data_get($data, 'request_counts.total', 0)
                    - (int) data_get($data, 'request_counts.completed', 0)
                    - (int) data_get($data, 'request_counts.failed', 0),
                succeeded: (int) data_get($data, 'request_counts.completed', 0),
                failed: (int) data_get($data, 'request_counts.failed', 0),
                total: (int) data_get($data, 'request_counts.total', 0),
            ),
            createdAt: data_get($data, 'created_at') !== null
                ? date('c', (int) data_get($data, 'created_at'))
                : null,
            expiresAt: data_get($data, 'expires_at') !== null
                ? date('c', (int) data_get($data, 'expires_at'))
                : null,
            endedAt: data_get($data, 'completed_at') !== null
                ? date('c', (int) data_get($data, 'completed_at'))
                : null,
            inputFileId: data_get($data, 'input_file_id'),
            outputFileId: data_get($data, 'output_file_id'),
            errorFileId: data_get($data, 'error_file_id'),
            errors: array_values(array_map(
                static fn (array $e): array => [
                    'code' => (string) data_get($e, 'code', ''),
                    'message' => (string) data_get($e, 'message', ''),
                    'line' => data_get($e, 'line') !== null ? (int) data_get($e, 'line') : null,
                    'param' => data_get($e, 'param') !== null ? (string) data_get($e, 'param') : null,
                ],
                data_get($data, 'errors.data', []) ?? []
            )),
        );
    }

    protected static function mapStatus(string $status): BatchStatus
    {
        return match ($status) {
            'validating' => BatchStatus::Validating,
            'failed' => BatchStatus::Failed,
            'in_progress' => BatchStatus::InProgress,
            'finalizing' => BatchStatus::Finalizing,
            'completed' => BatchStatus::Completed,
            'expired' => BatchStatus::Expired,
            'cancelling' => BatchStatus::Cancelling,
            'cancelled' => BatchStatus::Cancelled,
            default => throw new PrismException("Unknown OpenAI batch status: {$status}"),
        };
    }
}
