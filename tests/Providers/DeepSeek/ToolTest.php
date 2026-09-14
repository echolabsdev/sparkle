<?php

declare(strict_types=1);

use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toEqual([[
        'type' => 'function',
        'function' => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [
                    'query' => (object) [
                        'description' => 'the detailed search query',
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
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
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
                    'query' => (object) [
                        'description' => 'the detailed search query',
                        'type' => 'string',
                    ],
                ],
                'required' => $tool->requiredParameters(),
            ],
        ],
        'strict' => true,
    ]]);
});
