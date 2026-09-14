<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Files;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\UploadFileRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
    config()->set('prism.providers.anthropic.anthropic_beta', env('ANTHROPIC_BETA', 'files-api-2025-04-14'));
});

it('can upload a file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/files', 'anthropic/file-upload');

    $provider = Prism::provider('anthropic');

    $request = new UploadFileRequest(
        filename: 'data.jsonl',
        content: 'file content here',
    );

    $result = $provider->uploadFile($request);

    expect($result)->toBeInstanceOf(FileData::class)
        ->and($result->id)->toBe('file_011CZ3bbhcz6rgAXqe8pCLcX')
        ->and($result->filename)->toBe('data.jsonl')
        ->and($result->mimeType)->toBe('application/octet-stream')
        ->and($result->sizeBytes)->toBe(17)
        ->and($result->createdAt)->toBe('2026-03-14T20:23:33.521000Z')
        ->and($result->raw)->toBeArray();

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'v1/files'));
});

it('throws on error response', function (): void {
    Http::fake([
        'v1/files' => Http::response(json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid file format.',
            ],
        ]), 200),
    ])->preventStrayRequests();

    $provider = Prism::provider('anthropic');
    $provider->uploadFile(new UploadFileRequest(filename: 'bad.txt', content: 'bad'));
})->throws(PrismException::class, 'Anthropic Error: [invalid_request_error] Invalid file format.');
