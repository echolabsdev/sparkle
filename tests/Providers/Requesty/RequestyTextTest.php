<?php

declare(strict_types=1);

namespace Tests\Providers\Requesty;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.requesty.api_key', env('REQUESTY_API_KEY', 'req-1234'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'requesty/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Requesty, 'openai/gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->text)->toBe("Hello! I'm an AI assistant routed through Requesty. How can I help you today?");
    expect($response->finishReason)->toBe(FinishReason::Stop);
    expect($response->usage->promptTokens)->toBe(7);
    expect($response->usage->completionTokens)->toBe(19);
    expect($response->meta->id)->toBe('req-12345');
    expect($response->meta->model)->toBe('openai/gpt-4o');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toContain('chat/completions');
        expect($request->data()['model'])->toBe('openai/gpt-4o');
        expect($request->data()['messages'])->toBe([[
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => 'Who are you?',
            ]],
        ]]);

        return true;
    });
});

it('resolves gracefully on an unknown finish reason', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'req-67890',
            'model' => 'openai/gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Partial output.'],
                'finish_reason' => 'some_new_reason',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ]),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Requesty, 'openai/gpt-4o')
        ->withPrompt('Hi')
        ->asText();

    expect($response->text)->toBe('Partial output.');
    expect($response->finishReason)->toBe(FinishReason::Unknown);
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'openai/gpt-4o',
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
        ->using(Provider::Requesty, 'openai/gpt-4o')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
