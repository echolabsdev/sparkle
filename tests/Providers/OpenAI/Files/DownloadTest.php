<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Files;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\DownloadFileRequest;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-123'));
});

it('can download a file', function (): void {
    $fileId = 'file-DC8kDtzu39Q9PnLWRLVmLN';

    FixtureResponse::fakeResponseSequence("v1/files/$fileId/content", 'openai/file-download');

    $provider = Prism::provider('openai');
    $result = $provider->downloadFile(new DownloadFileRequest($fileId));

    expect($result)->toBe('file content here');
});
