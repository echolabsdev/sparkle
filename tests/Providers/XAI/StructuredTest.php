<?php

declare(strict_types=1);

namespace Tests\Providers\XAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.xai.api_key', env('XAI_API_KEY', 'fake-key'));
});

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema(
        name: 'movie_review',
        description: 'A structured movie review',
        properties: [
            new StringSchema('title', 'The movie title'),
            new StringSchema('rating', 'Rating out of 5 stars'),
            new StringSchema('summary', 'Brief review summary'),
        ],
        requiredFields: ['title', 'rating', 'summary']
    );

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('Review the movie Inception')
        ->asStructured();

    expect($response->structured)
        ->toBeArray()
        ->and($response->structured)->toHaveKeys([
            'title',
            'rating',
            'summary',
        ])
        ->and($response->structured['title'])->toBeString()
        ->and($response->structured['rating'])->toBeString()
        ->and($response->structured['summary'])->toBeString();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['model'] === 'grok-4'
            && isset($body['response_format'])
            && $body['response_format']['type'] === 'json_schema'
            && isset($body['response_format']['json_schema']);
    });
});

it('can generate structured output with system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

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
        ->using(Provider::XAI, 'grok-4')
        ->withSystemPrompt('You are a helpful weather and sports assistant.')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('schema strict defaults to null', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
    );

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withSystemPrompt('The game time is 3pm and the weather is 80° and sunny')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(array_keys(data_get($body, 'response_format.json_schema')))->not->toContain('strict');

        return true;
    });
});

it('uses meta to define strict mode', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-with-meta');

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
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->withProviderOptions([
            'schema' => ['strict' => true],
        ])
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'response_format.json_schema.strict'))->toBeTrue();

        return true;
    });
});

it('handles content returned as object instead of string', function (): void {
    // Some OpenAI-compatible providers (e.g. Cloudflare Workers AI) return
    // content as a parsed object instead of a JSON string with no "parsed" field.
    Http::fake([
        'v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 1731710832,
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => [
                            'title' => 'Inception',
                            'rating' => '4.5',
                            'summary' => 'A mind-bending masterpiece.',
                        ],
                        'refusal' => null,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 49,
                'completion_tokens' => 32,
                'total_tokens' => 81,
            ],
        ]),
    ]);

    $schema = new ObjectSchema(
        name: 'movie_review',
        description: 'A structured movie review',
        properties: [
            new StringSchema('title', 'The movie title'),
            new StringSchema('rating', 'Rating out of 5 stars'),
            new StringSchema('summary', 'Brief review summary'),
        ],
        requiredFields: ['title', 'rating', 'summary']
    );

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('Review the movie Inception')
        ->asStructured();

    expect($response->structured)
        ->toBeArray()
        ->and($response->structured)->toHaveKeys(['title', 'rating', 'summary'])
        ->and($response->structured['title'])->toBe('Inception')
        ->and($response->structured['rating'])->toBe('4.5')
        ->and($response->structured['summary'])->toBe('A mind-bending masterpiece.');
});

it('handles empty structured response gracefully', function (): void {
    Http::fake([
        'v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 1731710832,
            'model' => 'grok-4',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{}',
                        'parsed' => null,
                        'refusal' => null,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 2,
                'total_tokens' => 12,
            ],
        ]),
    ]);

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('result', 'The result'),
    ]);

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('Test prompt')
        ->asStructured();

    expect($response->structured)
        ->toBeArray()
        ->and($response->structured)->toBeEmpty();
});

it('throws an exception when there is a refusal', function (): void {
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('XAI Refusal: Could not process your request');

    Http::fake([
        'v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'refusal' => 'Could not process your request',
                        'content' => null,
                        'parsed' => null,
                    ],
                ],
            ],
        ]),
    ]);

    Http::preventStrayRequests();

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

    Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('answer', 'A simple answer'),
        ],
        ['answer']
    );

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withMaxTokens(1000)
        ->withPrompt('What is 2+2?')
        ->asStructured();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('uses default max_tokens when not specified', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('answer', 'A simple answer'),
        ],
        ['answer']
    );

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('What is 2+2?')
        ->asStructured();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.x.ai/v1/chat/completions'
            && $body['max_tokens'] === 2048;
    });
});

it('handles large max_tokens values', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'A simple answer'),
    ]);

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withMaxTokens(8192)
        ->withPrompt('What is 2+2?')
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['max_tokens'] === 8192;
    });
});

it('excludes null parameters from request', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'xai/structured-basic-response');

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'A simple answer'),
    ]);

    $response = Prism::structured()
        ->using(Provider::XAI, 'grok-4')
        ->withSchema($schema)
        ->withPrompt('What is 2+2?')
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        // Should not include temperature or top_p when not set
        expect($body)->not
            ->toHaveKey('temperature')
            ->and($body)->not->toHaveKey('top_p');

        return true;
    });
});
