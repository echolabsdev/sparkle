<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\Providers\Anthropic\Handlers\Batch\Results;

function anthropicResultsClient(): PendingRequest
{
    return Http::baseUrl('https://api.anthropic.com/v1');
}

it('parses succeeded JSONL results', function (): void {
    $batchId = 'msgbatch_01ABC';
    $jsonl = implode("\n", [
        json_encode([
            'custom_id' => 'request-1',
            'result' => [
                'type' => 'succeeded',
                'message' => [
                    'id' => 'msg_001',
                    'model' => 'claude-sonnet-4-20250514',
                    'content' => [['type' => 'text', 'text' => 'Hello there!']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 16],
                ],
            ],
        ]),
        json_encode([
            'custom_id' => 'request-2',
            'result' => [
                'type' => 'succeeded',
                'message' => [
                    'id' => 'msg_002',
                    'model' => 'claude-sonnet-4-20250514',
                    'content' => [['type' => 'text', 'text' => 'Hi again!']],
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 12],
                ],
            ],
        ]),
    ]);

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response($jsonl, 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toHaveCount(2)
        ->and($items[0])->toBeInstanceOf(BatchResultItem::class)
        ->and($items[0]->customId)->toBe('request-1')
        ->and($items[0]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($items[0]->text)->toBe('Hello there!')
        ->and($items[0]->usage->promptTokens)->toBe(10)
        ->and($items[0]->usage->completionTokens)->toBe(16)
        ->and($items[0]->messageId)->toBe('msg_001')
        ->and($items[0]->model)->toBe('claude-sonnet-4-20250514')
        ->and($items[1]->customId)->toBe('request-2')
        ->and($items[1]->status)->toBe(BatchResultStatus::Succeeded)
        ->and($items[1]->text)->toBe('Hi again!');
});

it('parses errored results', function (): void {
    $batchId = 'msgbatch_01ERR';
    $jsonl = json_encode([
        'custom_id' => 'request-bad',
        'result' => [
            'type' => 'errored',
            'error' => ['type' => 'overloaded_error', 'message' => 'Service overloaded.'],
        ],
    ]);

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response($jsonl, 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toHaveCount(1)
        ->and($items[0]->customId)->toBe('request-bad')
        ->and($items[0]->status)->toBe(BatchResultStatus::Errored)
        ->and($items[0]->errorType)->toBe('overloaded_error')
        ->and($items[0]->errorMessage)->toBe('Service overloaded.')
        ->and($items[0]->text)->toBeNull();
});

it('parses canceled results', function (): void {
    $batchId = 'msgbatch_01CAN';
    $jsonl = json_encode([
        'custom_id' => 'request-canceled',
        'result' => ['type' => 'canceled'],
    ]);

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response($jsonl, 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toHaveCount(1)
        ->and($items[0]->customId)->toBe('request-canceled')
        ->and($items[0]->status)->toBe(BatchResultStatus::Canceled);
});

it('parses expired results', function (): void {
    $batchId = 'msgbatch_01EXP';
    $jsonl = json_encode([
        'custom_id' => 'request-expired',
        'result' => ['type' => 'expired'],
    ]);

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response($jsonl, 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toHaveCount(1)
        ->and($items[0]->customId)->toBe('request-expired')
        ->and($items[0]->status)->toBe(BatchResultStatus::Expired);
});

it('skips blank lines in streamed JSONL', function (): void {
    $batchId = 'msgbatch_01BLK';
    $jsonl = "\n\n".json_encode([
        'custom_id' => 'req-1',
        'result' => [
            'type' => 'succeeded',
            'message' => [
                'id' => 'msg_001',
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ],
        ],
    ])."\n\n";

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response($jsonl, 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toHaveCount(1)
        ->and($items[0]->customId)->toBe('req-1');
});

it('returns empty array when response body is empty', function (): void {
    $batchId = 'msgbatch_01EMPTY';

    Http::fake([
        "https://api.anthropic.com/v1/messages/batches/{$batchId}/results" => Http::response('', 200),
    ])->preventStrayRequests();

    $items = (new Results(anthropicResultsClient()))->handle($batchId);

    expect($items)->toBeArray()->toBeEmpty();
});
