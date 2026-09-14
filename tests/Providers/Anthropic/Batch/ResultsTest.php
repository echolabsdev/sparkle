<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Batch\GetBatchResultsRequest;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
});

it('can get batch results', function (): void {
    $batchId = 'msgbatch_01MHsMbyYXTBsr4VGuENCexC';

    FixtureResponse::fakeResponseSequence("messages/batches/$batchId/results", 'anthropic/batch-results');

    $provider = Prism::provider('anthropic');
    $results = $provider->getBatchResults(new GetBatchResultsRequest($batchId));

    expect($results)->toHaveCount(2);

    // Assert first result
    expect($results[0])->toBeInstanceOf(BatchResultItem::class)
        ->and($results[0]->customId)->toBe('request-1')
        ->and($results[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($results[0]->text)->toBe('Hello! Nice to meet you. How are you doing today?')
        ->and($results[0]->usage)->not->toBeNull()
        ->and($results[0]->usage->promptTokens)->toBe(10)
        ->and($results[0]->usage->completionTokens)->toBe(16)
        ->and($results[0]->messageId)->toBe('msg_011q6qHe7t97tdeXV5HEaqy3')
        ->and($results[0]->model)->toBe('claude-sonnet-4-20250514')
        ->and($results[0]->errorType)->toBeNull()
        ->and($results[0]->errorMessage)->toBeNull();

    // Assert second result
    expect($results[1])->toBeInstanceOf(BatchResultItem::class)
        ->and($results[1]->customId)->toBe('request-2')
        ->and($results[1]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($results[1]->text)->toBe('Hello! Nice to see you again. How are you doing today?')
        ->and($results[1]->usage)->not->toBeNull()
        ->and($results[1]->usage->promptTokens)->toBe(10)
        ->and($results[1]->usage->completionTokens)->toBe(17)
        ->and($results[1]->messageId)->toBe('msg_01WRCGrkoNXQPJEycL2eEwsM')
        ->and($results[1]->model)->toBe('claude-sonnet-4-20250514')
        ->and($results[1]->errorType)->toBeNull()
        ->and($results[1]->errorMessage)->toBeNull();
});
