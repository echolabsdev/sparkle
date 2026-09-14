<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Structured Output for Replicate', function (): void {
    it('returns structured output with JSON mode', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/structured-json-mode');

        $schema = new ObjectSchema(
            'weather_info',
            'weather and game information',
            [
                new StringSchema('weather', 'The weather forecast'),
                new StringSchema('game_time', 'The game time'),
                new BooleanSchema('coat_required', 'whether a coat is required'),
            ],
            ['weather', 'game_time', 'coat_required']
        );

        $response = Prism::structured()
            ->withSchema($schema)
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys([
                'weather',
                'game_time',
                'coat_required',
            ])
            ->and($response->structured['weather'])->toBeString()
            ->and($response->structured['game_time'])->toBeString()
            ->and($response->structured['coat_required'])->toBeBool();
    });

    it('can handle simple structured output', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/structured-simple');

        $schema = new ObjectSchema(
            'person',
            'person information',
            [
                new StringSchema('name', 'The person name'),
                new StringSchema('role', 'The person role'),
            ],
            ['name', 'role']
        );

        $response = Prism::structured()
            ->withSchema($schema)
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Tell me about Albert Einstein')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['name', 'role']);
    });

    it('includes usage information in response', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/structured-json-mode');

        $schema = new ObjectSchema(
            'output',
            'output object',
            [
                new StringSchema('result', 'The result'),
            ],
            ['result']
        );

        $response = Prism::structured()
            ->withSchema($schema)
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Say hello')
            ->asStructured();

        expect($response->usage->promptTokens)->toBeGreaterThan(0)
            ->and($response->usage->completionTokens)->toBeGreaterThan(0);
    });
});
