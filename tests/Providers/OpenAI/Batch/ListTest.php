<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Batch\ListBatchesRequest;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can list batches', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches*' => Http::response(
            openaiFixture('batch-list-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->listBatches(new ListBatchesRequest(limit: 10));

    expect($result)->toBeInstanceOf(BatchListResult::class)
        ->and($result->data)->toHaveCount(2)
        ->and($result->hasMore)->toBeTrue()
        ->and($result->lastId)->toBe('batch_def456')
        ->and($result->data[0]->id)->toBe('batch_abc123')
        ->and($result->data[0]->status)->toBe(BatchStatus::Completed)
        ->and($result->data[0]->outputFileId)->toBe('file-output-xyz')
        ->and($result->data[1]->id)->toBe('batch_def456')
        ->and($result->data[1]->status)->toBe(BatchStatus::InProgress);
});
