<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Batch\CancelBatchRequest;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can cancel a batch', function (): void {
    Http::fake([
        '/v1/batches/*/cancel' => Http::response(
            openaiFixture('batch-cancel-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->cancelBatch(new CancelBatchRequest('batch_abc123'));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('batch_abc123')
        ->and($result->status)->toBe(BatchStatus::Cancelling)
        ->and($result->requestCounts->total)->toBe(2);
});
