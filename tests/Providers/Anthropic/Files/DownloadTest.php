<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Files;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\DownloadFileRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-123'));
    config()->set('prism.providers.anthropic.anthropic_beta', env('ANTHROPIC_BETA', 'files-api-2025-04-14'));
});

it('can download a file', function (): void {
    $fileId = 'file_011CZ3cYKGMZVroCGkmxwWkz';

    FixtureResponse::fakeResponseSequence("v1/files/$fileId/content", 'anthropic/file-download');

    $provider = Prism::provider('anthropic');

    $result = $provider->downloadFile(new DownloadFileRequest($fileId));

    expect($result)->toContain('This is a test file content');
});
