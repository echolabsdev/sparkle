<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Files;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\UploadFileRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can upload a file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/files', 'openai/file-upload');

    $provider = Prism::provider('openai');

    $request = new UploadFileRequest(
        filename: 'data.txt',
        content: 'file content here',
    );

    $result = $provider->uploadFile($request);

    expect($result)->toBeInstanceOf(FileData::class)
        ->and($result->id)->toBe('file-DC8kDtzu39Q9PnLWRLVmLN')
        ->and($result->filename)->toBe('data.txt')
        ->and($result->mimeType)->toBeNull()
        ->and($result->sizeBytes)->toBe(17)
        ->and($result->createdAt)->not->toBeNull()
        ->and($result->purpose)->toBe('user_data')
        ->and($result->raw)->toBeArray();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/files'));
});
