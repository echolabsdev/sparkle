<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Files;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\DeleteFileRequest;
use Prism\Prism\Files\DeleteFileResult;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
    config()->set('prism.providers.anthropic.anthropic_beta', env('ANTHROPIC_BETA', 'files-api-2025-04-14'));
});

it('can delete a file', function (): void {
    $fileId = 'file_011CZ3bbhcz6rgAXqe8pCLcX';

    FixtureResponse::fakeResponseSequence("v1/files/$fileId", 'anthropic/file-delete');

    $provider = Prism::provider('anthropic');

    $result = $provider->deleteFile(new DeleteFileRequest($fileId));

    expect($result)->toBeInstanceOf(DeleteFileResult::class)
        ->and($result->id)->toBe($fileId)
        ->and($result->deleted)->toBeTrue();
});
