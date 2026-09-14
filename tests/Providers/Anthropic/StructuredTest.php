<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Carbon;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('messages', 'anthropic/structured');

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
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
});

it('adds rate limit data to the responseMeta', function (): void {
    $requests_reset = Carbon::now()->addSeconds(30);

    FixtureResponse::fakeResponseSequence(
        'messages',
        'anthropic/structured',
        [
            'anthropic-ratelimit-requests-limit' => 1000,
            'anthropic-ratelimit-requests-remaining' => 500,
            'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
            'anthropic-ratelimit-input-tokens-limit' => 80000,
            'anthropic-ratelimit-input-tokens-remaining' => 0,
            'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
            'anthropic-ratelimit-output-tokens-limit' => 16000,
            'anthropic-ratelimit-output-tokens-remaining' => 15000,
            'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
            'anthropic-ratelimit-tokens-limit' => 96000,
            'anthropic-ratelimit-tokens-remaining' => 15000,
            'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
        ]
    );

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
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->meta->rateLimits)->toHaveCount(4);
    expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

it('can use adaptive thinking', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-with-extending-thinking');

    $response = Prism::structured()
        ->using('anthropic', 'claude-sonnet-4-6')
        ->withSchema(new ObjectSchema('output', 'the output object', [new StringSchema('text', 'the output text')], ['text']))
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
        ->asStructured();

    expect($response->structured['text'])->toContain('Douglas Adams');
    expect($response->additionalContent['thinking'])->toContain('meaning of life');
    expect($response->additionalContent)->toHaveKey('thinking_signature');
});

it('works with adaptive thinking when use_tool_calling is true', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/structured-with-use-tool-calling'
    );

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer about life, universe and everything'),
    ], ['answer']);

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-6')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['type' => 'adaptive'], 'use_tool_calling' => true])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('answer');
    expect($response->structured['answer'])->toBeString();

    expect($response->additionalContent)->toHaveKey('thinking');
    expect($response->additionalContent['thinking'])->toBeString();
    expect($response->additionalContent['thinking_signature'])->toBeString();
});

it('merges effort with output_config.format in the request payload', function (): void {
    Prism::fake();

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'coat_required']
    );

    $request = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-6')
        ->withPrompt('What is the weather?')
        ->withProviderOptions(['effort' => 'high']);

    $payload = Structured::buildHttpRequestPayload($request->toRequest());

    expect($payload)->toHaveKey('output_config');
    expect($payload['output_config'])->toBe([
        'effort' => 'high',
        'format' => [
            'type' => 'json_schema',
            'schema' => $schema->toArray(),
        ],
    ]);
});

it('can use legacy extended thinking', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-with-extending-thinking');

    $response = Prism::structured()
        ->using('anthropic', 'claude-3-7-sonnet-latest')
        ->withSchema(new ObjectSchema('output', 'the output object', [new StringSchema('text', 'the output text')], ['text']))
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asStructured();

    $expected_signature = 'ErUBCkYIBRgCIkDq/0akQPXjxyUDNYIEJDtoKAHfurgVvrSZvNZQ7K3QThwLAimdSKQvOP0FgzhRoOqKULqGMFxL37M87In0nFi/Egym1dqOTMScK1ZMjOUaDP0c8511Tm80nCJ6qCIwv126afgqa9mTvKJUlckcL4xVXvr9rtlWsqHmtnf2FnAj30h6SprQ2vtoalzQIwyHKh3wnlXkTFHhGyFc/EqMCorZ3qmWMr6zYCKCbojWzxgC';

    expect($response->structured['text'])->toContain('Douglas Adams');
    expect($response->additionalContent['thinking'])->toContain('meaning of life');
    expect($response->additionalContent['thinking_signature'])->toBe($expected_signature);

    expect($response->steps->last()->messages[1])
        ->additionalContent->thinking->toContain('meaning of life')
        ->additionalContent->thinking_signature->toBe($expected_signature);
});

it('throws error when citations and tool calling are used together', function (): void {
    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer'),
    ], ['answer']);

    expect(fn (): Response => Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What is the answer?')
        ->withProviderOptions(['citations' => true, 'use_tool_calling' => true])
        ->asStructured()
    )->toThrow(PrismException::class, 'Citations are not supported with tool calling mode');
});

it('works with legacy thinking mode when use_tool_calling is true', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/structured-with-use-tool-calling'
    );

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer about life, universe and everything'),
    ], ['answer']);

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true], 'use_tool_calling' => true])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('answer');
    expect($response->structured['answer'])->toBeString();

    expect($response->additionalContent)->toHaveKey('thinking');
    expect($response->additionalContent['thinking'])->toBeString();
    expect($response->additionalContent['thinking_signature'])->toBeString();
});

it('handles Chinese output with double quotes using tool calling', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-chinese-tool-calling');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast in Chinese with double quotes for temperature'),
            new StringSchema('recommendation', 'Clothing recommendation in Chinese with quoted items'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'recommendation', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('Respond in Chinese. Use double quotes around temperature values and clothing items.')
        ->withPrompt('What is the weather like today and what should I wear? The temperature is 15°C.')
        ->withProviderOptions(['use_tool_calling' => true])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'recommendation',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['recommendation'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();

    expect($response->structured['weather'])->toContain('今');
    expect($response->structured['weather'])->toContain('15°C');
    expect($response->structured['recommendation'])->toContain('建');
    expect($response->structured['coat_required'])->toBe(true);
});

it('uses native output format by default', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-with-native-output-format');

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
        ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys(['weather', 'game_time', 'coat_required']);
    expect($response->structured['weather'])->toBe('70º');
    expect($response->structured['game_time'])->toBe('3pm');
    expect($response->structured['coat_required'])->toBe(false);
});

it('uses native output format for complex nested structures', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-native-complex');

    $schema = new ObjectSchema(
        'contact_info',
        'contact information extracted from text',
        [
            new StringSchema('name', 'Full name'),
            new StringSchema('email', 'Email address'),
            new StringSchema('plan_interest', 'Plan they are interested in'),
            new BooleanSchema('demo_requested', 'Whether they requested a demo'),
        ],
        ['name', 'email', 'plan_interest', 'demo_requested']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
        ->withPrompt('Extract the key information from this email: John Smith (john@example.com) is interested in our Enterprise plan and wants to schedule a demo for next Tuesday at 2pm.')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured['name'])->toBe('John Smith');
    expect($response->structured['email'])->toBe('john@example.com');
    expect($response->structured['plan_interest'])->toBe('Enterprise');
    expect($response->structured['demo_requested'])->toBe(true);
});

it('sends output_config.format instead of deprecated output_format in the request payload', function (): void {
    Prism::fake();

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'coat_required']
    );

    $request = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
        ->withPrompt('What is the weather?');

    $payload = Structured::buildHttpRequestPayload($request->toRequest());

    expect($payload)->not->toHaveKey('output_format');
    expect($payload)->toHaveKey('output_config');
    expect($payload['output_config'])->toBe([
        'format' => [
            'type' => 'json_schema',
            'schema' => $schema->toArray(),
        ],
    ]);
});

it('throws error when citations and native output format are used together', function (): void {
    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer'),
    ], ['answer']);

    expect(fn (): Response => Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
        ->withPrompt('What is the answer?')
        ->withProviderOptions(['citations' => true])
        ->asStructured()
    )->toThrow(PrismException::class, 'Citations are not supported with native output_format');
});

it('allows automatic caching enabled via providerOptions', function (): void {
    Prism::fake();

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
        ],
        ['weather']
    );

    $request = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $payload = Structured::buildHttpRequestPayload($request->toRequest());

    expect($payload['cache_control'])->toBe(['type' => 'ephemeral']);
});
