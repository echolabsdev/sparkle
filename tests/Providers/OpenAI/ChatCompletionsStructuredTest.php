<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-test-1234'));
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

describe('Structured output for OpenAI chat/completions', function (): void {
    it('returns structured output', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/structured');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [
                new StringSchema('weather', 'The weather forecast'),
                new StringSchema('game_time', 'The tigers game time'),
                new BooleanSchema('coat_required', 'whether a coat is required'),
            ],
            ['weather', 'game_time', 'coat_required']
        );

        $response = Prism::structured()
            ->withSchema($schema)
            ->using('openai', 'gpt-4o-mini')
            ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStructured();

        // Assert response type
        expect($response)->toBeInstanceOf(StructuredResponse::class);

        // Assert structured data
        expect($response->structured)->toBeArray();
        expect($response->structured)->toHaveKeys([
            'weather',
            'game_time',
            'coat_required',
        ]);
        expect($response->structured['game_time'])->toBeString()->toBe('3pm');
        expect($response->structured['weather'])->toBeString()->toBe('75º');
        expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

        // Assert metadata
        expect($response->meta->id)->toBe('chatcmpl-struct123');
        expect($response->meta->model)->toBe('gpt-4o-mini');
        expect($response->usage->promptTokens)->toBe(172);
        expect($response->usage->completionTokens)->toBe(26);
    });

    it('sends requests to the chat/completions endpoint', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/structured');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [
                new StringSchema('weather', 'The weather forecast'),
            ],
            ['weather']
        );

        Prism::structured()
            ->withSchema($schema)
            ->using('openai', 'gpt-4o-mini')
            ->withPrompt('What is the weather?')
            ->asStructured();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'chat/completions')
            && ! str_contains($request->url(), 'responses'));
    });

    it('sends response_format json_object', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'openai-chat-completions/structured');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [
                new StringSchema('weather', 'The weather forecast'),
            ],
            ['weather']
        );

        Prism::structured()
            ->withSchema($schema)
            ->using('openai', 'gpt-4o-mini')
            ->withPrompt('What is the weather?')
            ->asStructured();

        Http::assertSent(function (Request $request): bool {
            expect($request->data()['response_format'])->toBe(['type' => 'json_object']);

            return true;
        });
    });
});
