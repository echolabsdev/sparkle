<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Batch\RetrieveBatchRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can retrieve a completed batch', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            openaiFixture('batch-retrieve-completed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->retrieveBatch(new RetrieveBatchRequest('batch_abc123'));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('batch_abc123')
        ->and($result->status)->toBe(BatchStatus::Completed)
        ->and($result->requestCounts->succeeded)->toBe(2)
        ->and($result->requestCounts->total)->toBe(2)
        ->and($result->requestCounts->failed)->toBe(0)
        ->and($result->inputFileId)->toBe('file-abc123')
        ->and($result->outputFileId)->toBe('file-output-xyz')
        ->and($result->endedAt)->not->toBeNull();
});

it('throws when provider returns an error in response body', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            json_encode([
                'error' => [
                    'message' => 'Batch not found.',
                    'type' => 'not_found_error',
                    'code' => 'batch_not_found',
                ],
            ]),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $provider->retrieveBatch(new RetrieveBatchRequest('batch_nonexistent'));
})->throws(PrismException::class, 'OpenAI Error: [not_found_error] Batch not found.');

it('maps errors from a failed batch', function (): void {
    Http::fake([
        'https://api.openai.com/v1/batches/*' => Http::response(
            openaiFixture('batch-retrieve-failed-1.json'),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->retrieveBatch(new RetrieveBatchRequest('batch_abc123'));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->status)->toBe(BatchStatus::Failed)
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors[0]['code'])->toBe('invalid_json_line')
        ->and($result->errors[0]['message'])->toBe('Invalid JSON on line 3.')
        ->and($result->errors[1]['code'])->toBe('token_limit_exceeded')
        ->and($result->errors[1]['message'])->toBe('Request exceeds token limit.');
});
