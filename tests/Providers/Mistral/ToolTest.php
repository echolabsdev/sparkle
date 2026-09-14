<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Prism\Prism\Providers\Mistral\Maps\ToolMap;
use Prism\Prism\Tool;
use stdClass;

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

it('maps tools without parameters to an object properties field', function (): void {
    $tool = (new Tool)
        ->as('ping')
        ->for('Pings the service')
        ->using(fn (): string => 'pong');

    $mapped = ToolMap::map([$tool]);

    expect($mapped[0]['function']['parameters']['properties'])->toBeInstanceOf(stdClass::class)
        ->and(json_encode($mapped[0]['function']['parameters']['properties']))->toBe('{}');
});
