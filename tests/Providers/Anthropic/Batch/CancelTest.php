<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Batch\CancelBatchRequest;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
});

it('can cancel a batch', function (): void {
    $batchId = 'msgbatch_013Zva2CMHLNnXjNJJKqJ2EF';

    FixtureResponse::fakeResponseSequence("messages/batches/$batchId/cancel", 'anthropic/batch-cancel');

    $provider = Prism::provider('anthropic');
    $result = $provider->cancelBatch(new CancelBatchRequest($batchId));

    expect($result)->toBeInstanceOf(BatchJob::class);
    expect($result->id)->not->toBeEmpty();
    expect($result->status)->toBe(BatchStatus::InProgress);
});
