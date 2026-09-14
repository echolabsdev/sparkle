<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-test-1234'));
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

describe('Text generation for OpenAI chat/completions', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text');

        $response = Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(13)
            ->and($response->usage->completionTokens)->toBe(16)
            ->and($response->meta->id)->toBe('chatcmpl-abc123def456')
            ->and($response->meta->model)->toBe('gpt-4o-mini')
            ->and($response->text)->toBe(
                "I'm an AI assistant powered by OpenAI. How can I help you today?"
            );
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withSystemPrompt('You are an ancient oracle.')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(37)
            ->and($response->usage->completionTokens)->toBe(14)
            ->and($response->meta->id)->toBe('chatcmpl-sys123def456')
            ->and($response->text)->toBe(
                'Greetings, mortal. I am the ancient oracle of the digital realm.'
            );
    });

    it('sends requests to the chat/completions endpoint', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text');

        Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->generate();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'chat/completions')
            && ! str_contains($request->url(), 'responses'));
    });

    it('sends messages in chat/completions format', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text');

        Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withSystemPrompt('Be helpful.')
            ->withPrompt('Who are you?')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'];

            // System message should be role: system with content string
            expect($messages[0]['role'])->toBe('system')
                ->and($messages[0]['content'])->toBe('Be helpful.');

            // User message should use 'text' type (not 'input_text')
            expect($messages[1]['role'])->toBe('user')
                ->and($messages[1]['content'][0]['type'])->toBe('text');

            return true;
        });
    });

    it('can generate text using tools', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text-with-tool-calls');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        ];

        $response = Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withTools($tools)
            ->withMaxSteps(2)
            ->withPrompt('What is the weather in Detroit?')
            ->generate();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('weather');
        expect($firstStep->toolCalls[0]->arguments())->toBe(['city' => 'Detroit']);

        // Verify the assistant message from step 1 is present in step 2's input messages
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(1);
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        // Assert final text content
        expect($response->text)->toBe(
            'The weather in Detroit is 75° and sunny. No coat needed!'
        );
    });

    it('sends tools in chat/completions format', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text-with-tool-calls');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        ];

        Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withTools($tools)
            ->withMaxSteps(2)
            ->withPrompt('What is the weather in Detroit?')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $tools = $request->data()['tools'] ?? null;
            if ($tools === null) {
                return true; // second request may not have tools
            }

            // Chat/completions format: nested under 'function' key
            expect($tools[0]['type'])->toBe('function')
                ->and($tools[0]['function']['name'])->toBe('weather');

            return true;
        });
    });
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'gpt-4o-mini',
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
        ->using('openai', 'gpt-4o-mini')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});

it('passes text_verbosity through to chat/completions', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions(['text_verbosity' => 'low'])
        ->generate();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'verbosity'))->toBe('low');

        return true;
    });
});

it('omits verbosity from chat/completions when text_verbosity is not set', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/generate-text');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->generate();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect($body)->not->toHaveKey('verbosity');

        return true;
    });
});
