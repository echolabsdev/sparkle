<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can create a batch from an inputFileId', function (): void {
    Http::fake([
        'v1/batches' => Http::response(openaiFixture('batch-create-1.json'), 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->batch(new BatchRequest(inputFileId: 'file-abc123'));

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('batch_abc123')
        ->and($result->status)->toBe(BatchStatus::Validating)
        ->and($result->requestCounts->total)->toBe(2)
        ->and($result->requestCounts->succeeded)->toBe(0)
        ->and($result->requestCounts->failed)->toBe(0)
        ->and($result->inputFileId)->toBe('file-abc123')
        ->and($result->outputFileId)->toBeNull()
        ->and($result->errorFileId)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        expect($body['input_file_id'])->toBe('file-abc123')
            ->and($body['endpoint'])->toBe('/v1/responses')
            ->and($body['completion_window'])->toBe('24h');

        return true;
    });
});

it('can create a batch from items, uploading JSONL automatically', function (): void {
    Http::fake([
        'v1/files' => Http::response(openaiFixture('file-upload-1.json'), 200),
        'v1/batches' => Http::response(openaiFixture('batch-create-1.json'), 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('openai');
    $result = $provider->batch(
        new BatchRequest(
            items: [
                new BatchRequestItem(
                    customId: 'request-1',
                    request: createOpenAITextRequest('Hello 1'),
                ),
                new BatchRequestItem(
                    customId: 'request-2',
                    request: createOpenAITextRequest('Hello 2'),
                ),
            ]
        )
    );

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->toBe('batch_abc123')
        ->and($result->status)->toBe(BatchStatus::Validating);

    Http::assertSentInOrder([
        // 1. File upload — JSONL content must be valid with correct structure
        function (Request $request): bool {
            $lines = array_filter(
                explode("\n", $request->body()),
                fn (string $line): bool => str_starts_with(trim($line), '{')
            );

            expect($lines)->toHaveCount(2);

            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                expect($decoded)->toHaveKey('custom_id')
                    ->and($decoded)->toHaveKey('method', 'POST')
                    ->and($decoded)->toHaveKey('url', '/v1/responses')
                    ->and($decoded['body'])->toHaveKey('model', 'gpt-4o');
            }

            return Str::contains($request->url(), 'v1/files');
        },
        // 2. Batch create — must use the file ID returned by the upload
        function (Request $request): bool {
            $body = json_decode($request->body(), true);

            expect($body['input_file_id'])->toBe('file-DC8kDtzu39Q9PnLWRLVmLN')
                ->and($body['endpoint'])->toBe('/v1/responses')
                ->and($body['completion_window'])->toBe('24h');

            return Str::contains($request->url(), 'v1/batches');
        },
    ]);
});

it('throws when both inputFileId and items are provided', function (): void {
    Http::fake()->preventStrayRequests();

    $provider = Prism::provider('openai');
    $provider->batch(new BatchRequest(
        items: [
            new BatchRequestItem(
                customId: 'request-1',
                request: createOpenAITextRequest('Hello 1'),
            ),
        ],
        inputFileId: 'file-abc123',
    ));
})->throws(PrismException::class, 'OpenAI batch requires either "inputFileId" or "items", not both.');

it('throws when neither inputFileId nor items are provided', function (): void {
    Http::fake()->preventStrayRequests();

    $provider = Prism::provider('openai');
    $provider->batch(new BatchRequest);
})->throws(PrismException::class, 'OpenAI batch requires either "inputFileId" or "items".');
