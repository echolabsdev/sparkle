<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchJobRequestCounts;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\Results;

function makeBatchJob(string $id, ?string $outputFileId): BatchJob
{
    return new BatchJob(
        id: $id,
        status: BatchStatus::Completed,
        requestCounts: new BatchJobRequestCounts(
            processing: 0,
            succeeded: 2,
            failed: 0,
            canceled: 0,
            expired: 0,
            total: 2,
        ),
        outputFileId: $outputFileId,
    );
}

it('parses succeeded results', function (): void {
    $jsonl = implode("\n", [
        json_encode([
            'custom_id' => 'my-first-request',
            'response' => [
                'status_code' => 200,
                'body' => [
                    'id' => 'resp_001',
                    'model' => 'gpt-4o-2024-08-06',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Hello!']],
                    ]],
                    'usage' => ['input_tokens' => 15, 'output_tokens' => 25],
                ],
            ],
            'error' => null,
        ]),
        json_encode([
            'custom_id' => 'my-second-request',
            'response' => [
                'status_code' => 200,
                'body' => [
                    'id' => 'resp_002',
                    'model' => 'gpt-4o-2024-08-06',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Nice to meet you!']],
                    ]],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 20],
                ],
            ],
            'error' => null,
        ]),
    ]);

    $results = new Results(
        retrieveBatch: fn (string $id): BatchJob => makeBatchJob($id, 'file-output-123'),
        downloadFile: fn (string $id): string => $jsonl,
    );

    $items = $results->handle('batch_abc');

    expect($items)->toHaveCount(2)
        ->and($items[0])->toBeInstanceOf(BatchResultItem::class)
        ->and($items[0]->customId)->toBe('my-first-request')
        ->and($items[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($items[0]->text)->toBe('Hello!')
        ->and($items[0]->usage->promptTokens)->toBe(15)
        ->and($items[0]->usage->completionTokens)->toBe(25)
        ->and($items[0]->messageId)->toBe('resp_001')
        ->and($items[0]->model)->toBe('gpt-4o-2024-08-06')
        ->and($items[0]->errorType)->toBeNull()
        ->and($items[1]->customId)->toBe('my-second-request')
        ->and($items[1]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($items[1]->text)->toBe('Nice to meet you!');
});

it('parses mixed results including errored and expired', function (): void {
    $jsonl = implode("\n", [
        json_encode([
            'custom_id' => 'req-success',
            'response' => [
                'status_code' => 200,
                'body' => [
                    'id' => 'resp_001',
                    'model' => 'gpt-4o',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Hello!']],
                    ]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ],
            'error' => null,
        ]),
        json_encode([
            'custom_id' => 'req-http-error',
            'response' => [
                'status_code' => 400,
                'body' => ['error' => ['message' => 'Invalid model', 'type' => 'invalid_request_error']],
            ],
            'error' => null,
        ]),
        json_encode([
            'custom_id' => 'req-expired',
            'response' => null,
            'error' => ['code' => 'batch_expired', 'message' => 'This batch has expired.'],
        ]),
    ]);

    $results = new Results(
        retrieveBatch: fn (string $id): BatchJob => makeBatchJob($id, 'file-output-456'),
        downloadFile: fn (string $id): string => $jsonl,
    );

    $items = $results->handle('batch_abc');

    expect($items)->toHaveCount(3)
        ->and($items[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($items[0]->customId)->toBe('req-success')
        ->and($items[1]->status)->toBe(BatchResultStatus::Errored)
        ->and($items[1]->customId)->toBe('req-http-error')
        ->and($items[1]->errorType)->toBe('http_error')
        ->and($items[2]->status)->toBe(BatchResultStatus::Expired)
        ->and($items[2]->customId)->toBe('req-expired')
        ->and($items[2]->errorMessage)->toBe('This batch has expired.');
});

it('skips blank lines in JSONL body', function (): void {
    $jsonl = "\n\n".json_encode([
        'custom_id' => 'req-1',
        'response' => [
            'status_code' => 200,
            'body' => [
                'id' => 'resp_001',
                'model' => 'gpt-4o',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Hi']],
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ],
        ],
        'error' => null,
    ])."\n\n";

    $results = new Results(
        retrieveBatch: fn (string $id): BatchJob => makeBatchJob($id, 'file-output-789'),
        downloadFile: fn (string $id): string => $jsonl,
    );

    $items = $results->handle('batch_abc');

    expect($items)->toHaveCount(1)
        ->and($items[0]->customId)->toBe('req-1');
});

it('returns an empty array when no output or error file is available', function (): void {
    $results = new Results(
        retrieveBatch: fn (string $id): BatchJob => makeBatchJob($id, null),
        downloadFile: fn (string $id): string => '',
    );

    expect($results->handle('batch_abc'))->toBeArray()->toBeEmpty();
});

it('forwards the batchId to the retrieve callback', function (): void {
    $capturedId = null;

    $results = new Results(
        retrieveBatch: function (string $id) use (&$capturedId): BatchJob {
            $capturedId = $id;

            return makeBatchJob($id, 'file-xyz');
        },
        downloadFile: fn (string $id): string => '',
    );

    $results->handle('batch_my-specific-id');

    expect($capturedId)->toBe('batch_my-specific-id');
});

it('forwards the outputFileId to the download callback', function (): void {
    $capturedFileId = null;

    $results = new Results(
        retrieveBatch: fn (string $id): BatchJob => makeBatchJob($id, 'file-the-output'),
        downloadFile: function (string $id) use (&$capturedFileId): string {
            $capturedFileId = $id;

            return '';
        },
    );

    $results->handle('batch_abc');

    expect($capturedFileId)->toBe('file-the-output');
});
