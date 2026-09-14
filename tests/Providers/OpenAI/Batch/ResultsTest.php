<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Batch\GetBatchResultsRequest;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can get batch results', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files/') && Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-results-1.jsonl'), 200);
        }

        if (Str::contains($request->url(), '/batches/')) {
            return Http::response(openaiFixture('batch-retrieve-completed-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $results = $provider->getBatchResults(new GetBatchResultsRequest('batch_abc123'));

    expect($results)->toHaveCount(2);

    // Assert first result
    expect($results[0])->toBeInstanceOf(BatchResultItem::class)
        ->and($results[0]->customId)->toBe('my-first-request')
        ->and($results[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($results[0]->text)->toBe('Hello! How can I help you today?')
        ->and($results[0]->usage)->not->toBeNull()
        ->and($results[0]->usage->promptTokens)->toBe(15)
        ->and($results[0]->usage->completionTokens)->toBe(25)
        ->and($results[0]->messageId)->toBe('resp_001')
        ->and($results[0]->model)->toBe('gpt-4o-2024-08-06')
        ->and($results[0]->errorType)->toBeNull()
        ->and($results[0]->errorMessage)->toBeNull();

    // Assert second result
    expect($results[1])->toBeInstanceOf(BatchResultItem::class)
        ->and($results[1]->customId)->toBe('my-second-request')
        ->and($results[1]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($results[1]->text)->toBe('Nice to meet you!')
        ->and($results[1]->usage)->not->toBeNull()
        ->and($results[1]->errorType)->toBeNull()
        ->and($results[1]->errorMessage)->toBeNull();
});

it('can get mixed batch results', function (): void {
    Http::fake(function (Request $request) {
        if (Str::contains($request->url(), '/files/') && Str::contains($request->url(), '/content')) {
            return Http::response(openaiFixture('batch-results-mixed-1.jsonl'), 200);
        }

        if (Str::contains($request->url(), '/batches/')) {
            return Http::response(openaiFixture('batch-retrieve-completed-1.json'), 200);
        }

        return Http::response('Not found', 404);
    });

    $provider = Prism::provider('openai');
    $results = $provider->getBatchResults(new GetBatchResultsRequest('batch_abc123'));

    expect($results)->toHaveCount(3);

    // Assert succeeded result
    expect($results[0]->customId)->toBe('req-success')
        ->and($results[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($results[0]->text)->toBe('Hello!')
        ->and($results[0]->errorType)->toBeNull()
        ->and($results[0]->errorMessage)->toBeNull();

    // Assert errored result
    expect($results[1]->customId)->toBe('req-http-error')
        ->and($results[1]->status)->toBe(BatchResultStatus::Errored)
        ->and($results[1]->errorType)->toBe('http_error')
        ->and($results[1]->text)->toBeNull();

    // Assert expired result
    expect($results[2]->customId)->toBe('req-expired')
        ->and($results[2]->status)->toBe(BatchResultStatus::Expired)
        ->and($results[2]->errorType)->toBe('batch_expired')
        ->and($results[2]->errorMessage)->toBe('This batch has expired.')
        ->and($results[2]->text)->toBeNull();
});

it('returns an empty array when batch results are not yet available', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            json_encode([
                'id' => 'batch_abc123',
                'object' => 'batch',
                'endpoint' => '/v1/responses',
                'status' => 'in_progress',
                'output_file_id' => null,
                'error_file_id' => null,
                'created_at' => 1727200644,
                'expires_at' => 1727287044,
                'completed_at' => null,
                'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 0],
            ]),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $results = $provider->getBatchResults(new GetBatchResultsRequest('batch_abc123'));

    expect($results)->toBeArray()->toBeEmpty();
});
