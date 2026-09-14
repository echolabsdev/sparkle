<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\ListBatchesRequest;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('can list batches', function (): void {
    FixtureResponse::fakeResponseSequence('messages/batches', 'anthropic/batch-list');

    $provider = Prism::provider('anthropic');
    $result = $provider->listBatches(new ListBatchesRequest);

    expect($result)->toBeInstanceOf(BatchListResult::class)
        ->and($result->data)->toBeArray()
        ->and($result->data)->toHaveLength(1)
        ->and($result->data[0]->id)->not->toBeEmpty();
});
