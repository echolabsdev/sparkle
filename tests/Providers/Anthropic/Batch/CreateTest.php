<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Create;
use Tests\Fixtures\FixtureResponse;

require_once __DIR__.'/Helpers.php';

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
});

it('can create a batch', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages/batches', 'anthropic/batch-create');

    $provider = Prism::provider('anthropic');
    $result = $provider->batch(createBatchRequest());

    expect($result)->toBeInstanceOf(BatchJob::class)
        ->and($result->id)->not->toBeEmpty()
        ->and($result->status)->toBe(BatchStatus::InProgress)
        ->and($result->requestCounts->total)->toBe(2)
        ->and($result->createdAt)->not->toBeNull()
        ->and($result->expiresAt)->not->toBeNull();

    Http::assertSent(static function (Request $request): bool {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        expect($body)->toHaveKey('requests')
            ->and($body['requests'])->toHaveCount(2)
            ->and($body['requests'][0])->toHaveKeys(['custom_id', 'params'])
            ->and($body['requests'][0]['custom_id'])->toBe('request-1')
            ->and($body['requests'][0]['params'])->toHaveKeys(['model', 'messages', 'max_tokens'])
            ->and($body['requests'][1]['custom_id'])->toBe('request-2');

        return true;
    });
});

it('throws when request count exceeds limit', function (): void {
    Http::fake()->preventStrayRequests();

    $items = [];
    for ($i = 0; $i < Create::MAX_REQUESTS + 1; $i++) {
        $items[] = new BatchRequestItem(
            customId: "req-{$i}",
            request: createTextRequest("Hello {$i}"),
        );
    }

    $provider = Prism::provider('anthropic');
    $provider->batch(new BatchRequest(items: $items));
})->throws(PrismException::class, 'Anthropic batch limit exceeded: 100001 requests submitted, maximum is 100,000.');

it('throws when payload size exceeds limit', function (): void {
    Http::fake()->preventStrayRequests();

    // Shrink the limit via config so the guard is exercised without
    // materializing multi-hundred-megabyte payloads in the test worker.
    config()->set('prism.anthropic.batch.max_payload_bytes', 1024);

    $items = [];
    for ($i = 0; $i < 10; $i++) {
        $items[] = new BatchRequestItem(
            customId: "req-{$i}",
            request: createTextRequest(str_repeat('A', 256)),
        );
    }

    $provider = Prism::provider('anthropic');
    $provider->batch(new BatchRequest(items: $items));
})->throws(PrismException::class, 'Anthropic request payload size exceeded the maximum of 1,024 bytes.');

it('throws when provider returns an error in response body', function (): void {
    Http::fake([
        '/v1/messages/batches' => Http::response(
            json_encode([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid batch request.',
                ],
            ], JSON_THROW_ON_ERROR),
            200
        ),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $provider->batch(createBatchRequest());
})->throws(PrismException::class, 'Anthropic Error: [invalid_request_error] Invalid batch request.');
