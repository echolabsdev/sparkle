<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Batch\RetrieveBatchRequest;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('can retrieve a batch', function (): void {
    $batchId = 'msgbatch_013Zva2CMHLNnXjNJJKqJ2EF';

    FixtureResponse::fakeResponseSequence("messages/batches/$batchId", 'anthropic/batch-retrieve');

    $provider = Prism::provider('anthropic');
    $result = $provider->retrieveBatch(new RetrieveBatchRequest($batchId));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('msgbatch_013Zva2CMHLNnXjNJJKqJ2EF')
        ->and($result->status)->toBe(BatchStatus::InProgress)
        ->and($result->requestCounts->processing)->toBe(100)
        ->and($result->requestCounts->succeeded)->toBe(50)
        ->and($result->requestCounts->failed)->toBe(30)
        ->and($result->requestCounts->canceled)->toBe(10)
        ->and($result->requestCounts->expired)->toBe(10)
        ->and($result->requestCounts->total)->toBe(200)
        ->and($result->createdAt)->toBe('2024-08-20T18:37:24.100435Z')
        ->and($result->expiresAt)->toBe('2024-08-20T18:37:24.100435Z')
        ->and($result->endedAt)->toBe('2024-08-20T18:37:24.100435Z')
        ->and($result->resultsUrl)->toBe('https://api.anthropic.com/v1/messages/batches/msgbatch_013Zva2CMHLNnXjNJJKqJ2EF/results')
        ->and($result->outputFileId)->toBeNull()
        ->and($result->errorFileId)->toBeNull();
});
