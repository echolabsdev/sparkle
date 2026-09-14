<?php

declare(strict_types=1);

namespace Tests\Providers\Requesty;

use Mockery;
use Prism\Prism\Providers\Requesty\Maps\ToolMap;
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

it('omits the parameters key for parameterless tools', function (): void {
    $tool = (new Tool)
        ->as('ping')
        ->for('Pings the service')
        ->using(fn (): string => 'pong');

    $mapped = ToolMap::map([$tool]);

    expect($mapped[0]['function'])->not->toHaveKey('parameters')
        ->and(array_keys($mapped[0]['function']))->toBe(['name', 'description']);
});

it('wraps empty parameters as an object when hasParameters is inconsistent', function (): void {
    $tool = Mockery::mock(Tool::class);
    $tool->shouldReceive('name')->andReturn('mock_tool');
    $tool->shouldReceive('description')->andReturn('A mock tool');
    $tool->shouldReceive('hasParameters')->andReturn(true);
    $tool->shouldReceive('parametersAsObject')->andReturn(new stdClass);
    $tool->shouldReceive('requiredParameters')->andReturn([]);
    $tool->shouldReceive('providerOptions')->with('strict')->andReturn(null);

    $mapped = ToolMap::map([$tool]);

    expect($mapped[0]['function']['parameters']['properties'])->toBeInstanceOf(stdClass::class);
});
