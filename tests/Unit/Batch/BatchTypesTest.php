<?php

declare(strict_types=1);

namespace Tests\Unit\Batch;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchJobRequestCounts;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\ValueObjects\Usage;

it('can create BatchStatus from all values', function (): void {
    expect(BatchStatus::Validating->value)->toBe('validating');
    expect(BatchStatus::InProgress->value)->toBe('in_progress');
    expect(BatchStatus::Finalizing->value)->toBe('finalizing');
    expect(BatchStatus::Completed->value)->toBe('completed');
    expect(BatchStatus::Failed->value)->toBe('failed');
    expect(BatchStatus::Cancelling->value)->toBe('cancelling');
    expect(BatchStatus::Cancelled->value)->toBe('cancelled');
    expect(BatchStatus::Expired->value)->toBe('expired');
    expect(BatchStatus::cases())->toHaveCount(8);
});

it('can create BatchResultStatus from all values', function (): void {
    expect(BatchResultStatus::Succeeded->value)->toBe('succeeded');
    expect(BatchResultStatus::Errored->value)->toBe('errored');
    expect(BatchResultStatus::Canceled->value)->toBe('canceled');
    expect(BatchResultStatus::Expired->value)->toBe('expired');
    expect(BatchResultStatus::cases())->toHaveCount(4);
});

it('can create BatchJob with all fields', function (): void {
    $job = new BatchJob(
        id: 'batch_123',
        status: BatchStatus::Completed,
        requestCounts: new BatchJobRequestCounts(
            processing: 0,
            succeeded: 10,
            failed: 2,
            canceled: 1,
            expired: 0,
            total: 13,
        ),
        createdAt: '2024-09-24T18:00:00Z',
        expiresAt: '2024-09-25T18:00:00Z',
        endedAt: '2024-09-24T19:00:00Z',
        resultsUrl: 'https://example.com/results',
        outputFileId: 'file-out-123',
        errorFileId: 'file-err-456',
    );

    expect($job->id)->toBe('batch_123');
    expect($job->status)->toBe(BatchStatus::Completed);
    expect($job->requestCounts->processing)->toBe(0);
    expect($job->requestCounts->succeeded)->toBe(10);
    expect($job->requestCounts->failed)->toBe(2);
    expect($job->requestCounts->canceled)->toBe(1);
    expect($job->requestCounts->expired)->toBe(0);
    expect($job->requestCounts->total)->toBe(13);
    expect($job->createdAt)->toBe('2024-09-24T18:00:00Z');
    expect($job->expiresAt)->toBe('2024-09-25T18:00:00Z');
    expect($job->endedAt)->toBe('2024-09-24T19:00:00Z');
    expect($job->resultsUrl)->toBe('https://example.com/results');
    expect($job->outputFileId)->toBe('file-out-123');
    expect($job->errorFileId)->toBe('file-err-456');
});

it('can create BatchJob with minimal fields', function (): void {
    $job = new BatchJob(
        id: 'batch_456',
        status: BatchStatus::InProgress,
        requestCounts: new BatchJobRequestCounts,
    );

    expect($job->id)->toBe('batch_456');
    expect($job->status)->toBe(BatchStatus::InProgress);
    expect($job->requestCounts->processing)->toBe(0);
    expect($job->requestCounts->succeeded)->toBe(0);
    expect($job->requestCounts->failed)->toBe(0);
    expect($job->requestCounts->canceled)->toBe(0);
    expect($job->requestCounts->expired)->toBe(0);
    expect($job->requestCounts->total)->toBe(0);
    expect($job->createdAt)->toBeNull();
    expect($job->expiresAt)->toBeNull();
    expect($job->endedAt)->toBeNull();
    expect($job->resultsUrl)->toBeNull();
    expect($job->outputFileId)->toBeNull();
    expect($job->errorFileId)->toBeNull();
});

it('can create BatchListResult', function (): void {
    $jobs = [
        new BatchJob('b1', BatchStatus::Completed, new BatchJobRequestCounts),
        new BatchJob('b2', BatchStatus::InProgress, new BatchJobRequestCounts),
    ];

    $result = new BatchListResult(
        data: $jobs,
        hasMore: true,
        lastId: 'b2',
    );

    expect($result->data)->toHaveCount(2);
    expect($result->data[0]->id)->toBe('b1');
    expect($result->data[1]->id)->toBe('b2');
    expect($result->hasMore)->toBeTrue();
    expect($result->lastId)->toBe('b2');
});

it('can create BatchListResult with defaults', function (): void {
    $result = new BatchListResult(data: []);

    expect($result->data)->toBeEmpty();
    expect($result->hasMore)->toBeFalse();
    expect($result->lastId)->toBeNull();
});

it('can create BatchJobRequestCounts with defaults', function (): void {
    $counts = new BatchJobRequestCounts;

    expect($counts->processing)->toBe(0);
    expect($counts->succeeded)->toBe(0);
    expect($counts->failed)->toBe(0);
    expect($counts->canceled)->toBe(0);
    expect($counts->expired)->toBe(0);
    expect($counts->total)->toBe(0);
});

it('can create BatchResultItem for succeeded result', function (): void {
    $item = new BatchResultItem(
        customId: 'req-1',
        status: BatchResultStatus::Succeeded,
        text: 'Hello!',
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        messageId: 'msg_123',
        model: 'claude-3-5-sonnet-20240620',
    );

    expect($item->customId)->toBe('req-1');
    expect($item->status)->toBe(BatchResultStatus::Succeeded);
    expect($item->text)->toBe('Hello!');
    expect($item->usage->promptTokens)->toBe(10);
    expect($item->usage->completionTokens)->toBe(5);
    expect($item->messageId)->toBe('msg_123');
    expect($item->model)->toBe('claude-3-5-sonnet-20240620');
    expect($item->errorType)->toBeNull();
    expect($item->errorMessage)->toBeNull();
});

it('can create BatchResultItem for errored result', function (): void {
    $item = new BatchResultItem(
        customId: 'req-2',
        status: BatchResultStatus::Errored,
        errorType: 'invalid_request',
        errorMessage: 'Model not found.',
    );

    expect($item->customId)->toBe('req-2');
    expect($item->status)->toBe(BatchResultStatus::Errored);
    expect($item->text)->toBeNull();
    expect($item->usage)->toBeNull();
    expect($item->messageId)->toBeNull();
    expect($item->model)->toBeNull();
    expect($item->errorType)->toBe('invalid_request');
    expect($item->errorMessage)->toBe('Model not found.');
});

it('can create BatchResultItem for canceled result', function (): void {
    $item = new BatchResultItem(
        customId: 'req-3',
        status: BatchResultStatus::Canceled,
    );

    expect($item->customId)->toBe('req-3');
    expect($item->status)->toBe(BatchResultStatus::Canceled);
    expect($item->text)->toBeNull();
    expect($item->errorType)->toBeNull();
});

it('can create BatchResultItem for expired result', function (): void {
    $item = new BatchResultItem(
        customId: 'req-4',
        status: BatchResultStatus::Expired,
    );

    expect($item->customId)->toBe('req-4');
    expect($item->status)->toBe(BatchResultStatus::Expired);
});
