<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Files;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\GetFileMetadataRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
    config()->set('prism.providers.anthropic.anthropic_beta', env('ANTHROPIC_BETA', 'files-api-2025-04-14'));
});

it('can get file metadata', function (): void {
    $fileId = 'file_011CZ3cYKGMZVroCGkmxwWkz';

    FixtureResponse::fakeResponseSequence("v1/files/$fileId", 'anthropic/file-get-metadata');

    $provider = Prism::provider(Provider::Anthropic, [
        'anthropic_beta' => 'files-api-2025-04-14',
    ]);

    $result = $provider->getFileMetadata(new GetFileMetadataRequest($fileId));

    expect($result)->toBeInstanceOf(FileData::class)
        ->and($result->id)->toBe($fileId)
        ->and($result->filename)->toBe('test1.txt')
        ->and($result->mimeType)->toBe('text/plain')
        ->and($result->sizeBytes)->toBe(17)
        ->and($result->createdAt)->toBe('2026-03-14T20:35:54.416000Z')
        ->and($result->purpose)->toBeNull();
});
