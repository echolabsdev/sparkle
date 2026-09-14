<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Files;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Files\DeleteFileRequest;
use Prism\Prism\Files\DeleteFileResult;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-1234'));
});

it('can delete a file', function (): void {
    $fileId = 'file-DC8kDtzu39Q9PnLWRLVmLN';

    FixtureResponse::fakeResponseSequence("v1/files/$fileId", 'openai/file-delete');

    $provider = Prism::provider('openai');
    $result = $provider->deleteFile(new DeleteFileRequest($fileId));

    expect($result)->toBeInstanceOf(DeleteFileResult::class)
        ->and($result->id)->toBe($fileId)
        ->and($result->deleted)->toBeTrue();
});
