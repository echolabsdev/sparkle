<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Request as TextRequest;

function createOpenAITextRequest(string $prompt): TextRequest
{
    return Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->toRequest();
}

function openaiFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../../Fixtures/openai/'.$name);
}
