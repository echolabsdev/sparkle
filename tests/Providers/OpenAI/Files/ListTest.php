<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Files;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\FileListResult;
use Prism\Prism\Files\ListFilesRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can list files', function (): void {
    FixtureResponse::fakeResponseSequence('v1/files', 'openai/file-list');

    $provider = Prism::provider('openai');
    $result = $provider->listFiles(new ListFilesRequest);

    expect($result)->toBeInstanceOf(FileListResult::class)
        ->and($result->data)->toHaveCount(55)
        ->and($result->hasMore)->toBeFalse()
        ->and($result->firstId)->toBe('file-DC8kDtzu39Q9PnLWRLVmLN')
        ->and($result->lastId)->toBe('file-EAlawNWTLJFO3VlA0m3T9soM');
});

it('sends pagination parameters', function (): void {
    FixtureResponse::fakeResponseSequence('v1/files?limit=10&after=file-abc123', 'openai/file-list');

    $provider = Prism::provider('openai');
    $provider->listFiles(new ListFilesRequest(limit: 10, afterId: 'file-abc123'));

    Http::assertSent(fn (Request $request): bool => $request->toPsrRequest()->getUri()->getQuery() === 'limit=10&after=file-abc123');
});
