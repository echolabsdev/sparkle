<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast', true),
            new BooleanSchema('coat_required', 'whether a coat is required', true),
            new EnumSchema('game_time', 'The time of the game', ['1:00 PM', '7:00 PM'], true),
            new NumberSchema('temperature', 'The temperature in Fahrenheit', true),
            new ObjectSchema(
                'location',
                'The location of the game',
                [
                    new StringSchema('city', 'The city', true),
                    new StringSchema('state', 'The state', true),
                ],
                ['city', 'state'],
                false,
                true
            ),
            new ArraySchema(
                'players',
                'The players in the game',
                new StringSchema('player', 'The player', true),
                true
            ),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
        'temperature',
        'location',
        'players',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
    expect($response->structured['temperature'])->toBeInt();
    expect($response->structured['location'])->toBeArray();
    expect($response->structured['location'])->toHaveKeys(['city', 'state']);
    expect($response->structured['location']['city'])->toBeString();
    expect($response->structured['location']['state'])->toBeString();
    expect($response->structured['players'])->toBeArray();
    expect($response->structured['players'][0])->toBeString();

    expect($response->usage->promptTokens)->toBe(81);
    expect($response->usage->completionTokens)->toBe(64);
});

it('can use a cache object with a structured request', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/use-cache-with-structured');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $object = $provider->cache(
        model: 'gemini-1.5-flash-002',
        messages: [
            new UserMessage('', [
                Document::fromLocalPath('tests/Fixtures/long-document.pdf'),
            ]),
        ],
        systemPrompts: [
            new SystemMessage('You are a legal analyst.'),
        ],
        ttl: 30
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema(new ObjectSchema('answer', '', [
            new StringSchema('legal_jurisdiction', 'Which legal jurisdiction is this document from?'),
            new StringSchema('legislation_type', 'What type of legislation is this (e.g. a treaty, a regulation, an act, a directive, etc.)?'),
            new NumberSchema('article_count', 'How many articles does the main body of the legislation contain?'),
        ]))
        ->withProviderOptions(['cachedContentName' => $object->name])
        ->withPrompt('Summarise this document using the properties and descriptions defined in the schema.')
        ->asStructured();

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() == 'https://generativelanguage.googleapis.com/v1beta/cachedContents',
        fn (Request $request): bool => $request->data()['cachedContent'] === $object->name,
    ]);

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'legal_jurisdiction',
        'legislation_type',
        'article_count',
    ]);

    expect($response->usage->cacheReadInputTokens)->toBe(88759);
    expect($response->structured['article_count'])->toBe(358);
    expect($response->structured['legal_jurisdiction'])->toBe('European Union');
    expect($response->structured['legislation_type'])->toBe('Treaty');
});

it('works with allowAdditionalProperties set to true', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured');

    $schema = new ObjectSchema(
        'book_info',
        'Book information',
        [
            new StringSchema('title', 'Book title'),
            new StringSchema('author', 'Book author'),
        ],
        ['title', 'author'],
        true  // allowAdditionalProperties: true
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema($schema)
        ->withPrompt('Generate information about a book')
        ->asStructured();

    expect($response->structured)->toBeArray();
    // The fixture data contains different keys, so we'll just verify it's not empty
    expect($response->structured)->not->toBeEmpty();
    expect($response->usage->promptTokens)->toBe(81);
    expect($response->usage->completionTokens)->toBe(64);
});

it('supports AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured-anyof-simple');

    // Create a schema where a field can accept multiple types
    $schema = new ObjectSchema(
        'response',
        'Response with flexible value',
        [
            new AnyOfSchema(
                schemas: [
                    new StringSchema('text', 'A text value'),
                    new NumberSchema('number', 'A numeric value'),
                ],
                name: 'value',
                description: 'Can be text or number'
            ),
        ],
        ['value']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Return the text "forty-two"')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('value');
    expect($response->structured['value'])->toBeString();
    expect($response->structured['value'])->toBe('forty-two');

    Http::assertSent(function (Request $request): bool {
        $schema = $request->data()['generationConfig']['response_json_schema'];

        expect($schema)->toHaveKey('properties');
        expect($schema['properties'])->toHaveKey('value');
        expect($schema['properties']['value'])->toHaveKey('anyOf');
        expect($schema['properties']['value']['anyOf'])->toHaveCount(2);

        expect($schema['properties']['value']['anyOf'][0])->not->toHaveKey('name');
        expect($schema['properties']['value']['anyOf'][1])->not->toHaveKey('name');

        expect($schema['properties']['value']['anyOf'][0])->toHaveKey('type');
        expect($schema['properties']['value']['anyOf'][1])->toHaveKey('type');

        return true;
    });
});

it('supports AnyOfSchema with complex objects', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured-anyof-complex');

    $articleSchema = new ObjectSchema(
        'article',
        'A blog article',
        [
            new StringSchema('title', 'Article title'),
            new StringSchema('content', 'Article content'),
        ],
        ['title', 'content']
    );

    $imageSchema = new ObjectSchema(
        'image',
        'An image post',
        [
            new StringSchema('url', 'Image URL'),
            new NumberSchema('width', 'Width in pixels'),
        ],
        ['url']
    );

    $schema = new ObjectSchema(
        'post',
        'Social media post',
        [
            new AnyOfSchema(
                schemas: [$articleSchema, $imageSchema],
                name: 'content',
                description: 'Article or image'
            ),
        ],
        ['content']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Create an article post about AI')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('content');
    expect($response->structured['content'])->toBeArray();
    expect($response->structured['content'])->toHaveKey('title');
    expect($response->structured['content'])->toHaveKey('content');
    expect($response->structured['content']['title'])->toBe('Understanding AI');

    Http::assertSent(function (Request $request): bool {
        $schema = $request->data()['generationConfig']['response_json_schema'];
        $anyOf = $schema['properties']['content']['anyOf'];

        expect($anyOf)->toHaveCount(2);

        foreach ($anyOf as $nestedSchema) {
            expect($nestedSchema)->not->toHaveKey('name');
            expect($nestedSchema['additionalProperties'])->toBeFalse();
            expect($nestedSchema)->toHaveKey('type');
            expect($nestedSchema['type'])->toBe('object');
            expect($nestedSchema)->toHaveKey('properties');
            expect($nestedSchema)->toHaveKey('required');
        }

        foreach ($anyOf as $nestedSchema) {
            foreach ($nestedSchema['properties'] as $property) {
                expect($property)->not->toHaveKey('name');
            }
        }

        return true;
    });
});

it('supports NumberSchema constraints in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured-with-number-constraints');

    $schema = new ObjectSchema(
        'rating',
        'Product rating',
        [
            new NumberSchema(
                name: 'score',
                description: 'Rating score',
                maximum: 5.0,
                minimum: 1.0
            ),
        ],
        ['score']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Rate this product 4.5 out of 5')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('score');
    expect($response->structured['score'])->toBeFloat();
    expect($response->structured['score'])->toBe(4.5);
    expect($response->structured['score'])->toBeGreaterThanOrEqual(1.0);
    expect($response->structured['score'])->toBeLessThanOrEqual(5.0);

    Http::assertSent(function (Request $request): bool {
        $schema = $request->data()['generationConfig']['response_json_schema'];

        expect($schema['properties'])->toHaveKey('score');
        expect($schema['properties']['score'])->toHaveKey('minimum');
        expect($schema['properties']['score'])->toHaveKey('maximum');
        expect($schema['properties']['score']['minimum'])->toBe(1.0);
        expect($schema['properties']['score']['maximum'])->toBe(5.0);
        expect($schema['properties']['score'])->not->toHaveKey('name');

        return true;
    });
});

it('supports nullable AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured-anyof-nullable');

    $schema = new ObjectSchema(
        'response',
        'Response with optional value',
        [
            new AnyOfSchema(
                schemas: [
                    new StringSchema('text', 'A text value'),
                    new NumberSchema('number', 'A numeric value'),
                ],
                name: 'value',
                description: 'Can be text, number, or null',
                nullable: true
            ),
        ],
        ['value']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Return a null value')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('value');
    expect($response->structured['value'])->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $schema = $request->data()['generationConfig']['response_json_schema'];
        $anyOf = $schema['properties']['value']['anyOf'];

        expect($anyOf)->toHaveCount(3);

        $lastElement = $anyOf[count($anyOf) - 1];
        expect($lastElement)->toBe(['type' => 'null']);

        expect($anyOf[0])->not->toHaveKey('name');
        expect($anyOf[1])->not->toHaveKey('name');

        return true;
    });
});

it('filters out thought parts when includeThoughts is true', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured-with-thoughts');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('result', 'The result'),
        ],
        ['result']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.0-flash-exp')
        ->withSchema($schema)
        ->withPrompt('Extract some information')
        ->withProviderOptions([
            'thinkingConfig' => [
                'thinkingLevel' => 'low',
                'includeThoughts' => true,
            ],
        ])
        ->asStructured();

    // Should extract the JSON from the non-thought part, not the thinking content
    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('result');
    expect($response->structured['result'])->toBe('Successfully extracted information');

    // Thought summaries should be available in additionalContent
    expect($response->steps[0]->additionalContent)->toHaveKey('thoughtSummaries');
    expect($response->steps[0]->additionalContent['thoughtSummaries'])->toBeArray();
    expect($response->steps[0]->additionalContent['thoughtSummaries'][0])->toContain('Let me think about');
});

it('sends topK in generationConfig for structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured');

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema(new ObjectSchema(
            'output',
            'the output object',
            [
                new StringSchema('weather', 'The weather forecast'),
                new BooleanSchema('coat_required', 'whether a coat is required'),
            ],
            ['weather', 'coat_required'],
        ))
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->usingTopK(40)
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data['generationConfig'])
            ->toHaveKey('topK')
            ->and($data['generationConfig']['topK'])->toBe(40);

        return true;
    });
});

it('passes service_tier in the request body for structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
        ],
        ['weather']
    );

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('What is the weather?')
        ->withProviderOptions(['serviceTier' => 'flex'])
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data)->toHaveKey('service_tier')
            ->and($data['service_tier'])->toBe('flex');

        return true;
    });
});
