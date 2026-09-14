<?php

declare(strict_types=1);

namespace Tests\Providers\Azure;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.azure.url', env('AZURE_AI_URL', 'https://test-resource.openai.azure.com'));
    config()->set('prism.providers.azure.api_key', env('AZURE_AI_API_KEY', 'azure-key-1234'));
    config()->set('prism.providers.azure.api_version', env('AZURE_AI_API_VERSION', '2024-10-21'));
    config()->set('prism.providers.azure.deployment_name', env('AZURE_AI_DEPLOYMENT', 'gpt-4o'));
});

it('can generate text with a prompt', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-azure-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from Azure!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 5,
            ],
        ]),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Azure, 'gpt-4o')
        ->withPrompt('Hello')
        ->asText();

    expect($response->text)->toBe('Hello from Azure!')
        ->and($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(5)
        ->and($response->usage->cacheReadInputTokens)->toBeNull();
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 10,
                'prompt_tokens_details' => ['cached_tokens' => 60],
            ],
        ]),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Azure, 'gpt-4o')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
