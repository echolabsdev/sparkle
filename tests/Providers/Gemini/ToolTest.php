<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

it('maps tools to gemini format', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->withObjectParameter(
            'options',
            'additional options',
            [
                new StringSchema('option1', 'description for option1'),
            ]
        )
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toEqual([[
        'name' => $tool->name(),
        'description' => $tool->description(),
        'parameters' => [
            'type' => 'object',
            // The `properties` map and each schema in it are JSON OBJECTS.
            // One level: a nested schema's own `properties` stays an array,
            // which is right — ObjectSchema drops the key when it is empty, so
            // there is no empty container to get wrong down there.
            'properties' => (object) [
                'query' => (object) [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
                'options' => (object) [
                    'description' => 'additional options',
                    'type' => 'object',
                    'properties' => [
                        'option1' => [
                            'description' => 'description for option1',
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            'required' => ['query', 'options'],
        ],
    ]]);
});

it('maps multiple tools', function (): void {
    $tools = [
        (new Tool)
            ->as('search')
            ->for('Searching the web')
            ->withStringParameter('query', 'the detailed search query')
            ->using(fn (): string => '[Search results]'),
        (new Tool)
            ->as('weather')
            ->for('Get weather info')
            ->withStringParameter('location', 'the location')
            ->using(fn (): string => '[Weather info]'),
    ];

    $mapped = ToolMap::map($tools);
    expect($mapped)->toHaveCount(2);
    expect($mapped[0]['name'])->toBe('search');
    expect($mapped[1]['name'])->toBe('weather');
});

it('returns empty array for no tools', function (): void {
    expect(ToolMap::map([]))->toBe([]);
});
