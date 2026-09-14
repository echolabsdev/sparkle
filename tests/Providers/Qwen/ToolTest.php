<?php

declare(strict_types=1);

use Prism\Prism\Providers\Qwen\Maps\ToolMap;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('weather')
        ->for('Searching for weather conditions')
        ->withStringParameter('city', 'the city to get weather for')
        ->using(fn (): string => '[Weather results]');

    expect(ToolMap::map([$tool]))->toEqual([[
        'type' => 'function',
        'function' => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [
                    'city' => (object) [
                        'description' => 'the city to get weather for',
                        'type' => 'string',
                    ],
                ],
                'required' => $tool->requiredParameters(),
            ],
        ],
    ]]);
});

it('maps tools with strict mode', function (): void {
    $tool = (new Tool)
        ->as('weather')
        ->for('Searching for weather conditions')
        ->withStringParameter('city', 'the city to get weather for')
        ->using(fn (): string => '[Weather results]')
        ->withProviderOptions([
            'strict' => true,
        ]);

    expect(ToolMap::map([$tool]))->toEqual([[
        'type' => 'function',
        'function' => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [
                    'city' => (object) [
                        'description' => 'the city to get weather for',
                        'type' => 'string',
                    ],
                ],
                'required' => $tool->requiredParameters(),
            ],
        ],
        'strict' => true,
    ]]);
});
