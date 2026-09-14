<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Files;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\FileListResult;
use Prism\Prism\Files\ListFilesRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
    config()->set('prism.providers.anthropic.anthropic_beta', env('ANTHROPIC_BETA', 'files-api-2025-04-14'));
});

it('can list files', function (): void {
    FixtureResponse::fakeResponseSequence('v1/files', 'anthropic/list-files');

    $provider = Prism::provider(Provider::Anthropic);

    $result = $provider->listFiles(new ListFilesRequest);

    expect($result)->toBeInstanceOf(FileListResult::class)
        ->and($result->data)->toHaveCount(2)
        ->and($result->hasMore)->toBeFalse()
        ->and($result->firstId)->toBe('file_011CZ3cYKGMZVroCGkmxwWkz')
        ->and($result->lastId)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->data[0])->toBeInstanceOf(FileData::class)
        ->and($result->data[0]->id)->toBe('file_011CZ3cYKGMZVroCGkmxwWkz')
        ->and($result->data[0]->filename)->toBe('test1.txt')
        ->and($result->data[0]->mimeType)->toBe('text/plain')
        ->and($result->data[0]->sizeBytes)->toBe(17)
        ->and($result->data[0]->createdAt)->toBe('2026-03-14T20:35:54.416000Z')
        ->and($result->data[1])->toBeInstanceOf(FileData::class)
        ->and($result->data[1]->id)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->data[1]->filename)->toBe('data.jsonl')
        ->and($result->data[1]->mimeType)->toBe('application/octet-stream')
        ->and($result->data[1]->sizeBytes)->toBe(17)
        ->and($result->data[1]->createdAt)->toBe('2026-03-14T20:23:33.521000Z');
});

it('sends pagination parameters', function (): void {
    $afterId = 'file_011CZ3cYKGMZVroCGkmxwWkz';

    FixtureResponse::fakeResponseSequence("v1/files?limit=1&after_id=$afterId", 'anthropic/list-files-with-pagination');

    $provider = Prism::provider(Provider::Anthropic, [
        'anthropic_beta' => 'files-api-2025-04-14',
    ]);

    $result = $provider->listFiles(new ListFilesRequest(limit: 1, afterId: $afterId));

    expect($result)->toBeInstanceOf(FileListResult::class)
        ->and($result->data)->toHaveCount(1)
        ->and($result->hasMore)->toBeFalse()
        ->and($result->firstId)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->lastId)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->data[0])->toBeInstanceOf(FileData::class)
        ->and($result->data[0]->id)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->data[0]->filename)->toBe('data.jsonl')
        ->and($result->data[0]->mimeType)->toBe('application/octet-stream')
        ->and($result->data[0]->sizeBytes)->toBe(17)
        ->and($result->data[0]->createdAt)->toBe('2026-03-14T20:23:33.521000Z');

    Http::assertSent(fn (Request $request): bool => $request->toPsrRequest()->getUri()->getQuery() === "limit=1&after_id=$afterId");
});
