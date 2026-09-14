<?php

declare(strict_types=1);

namespace Tests\Providers\Groq;

use Prism\Prism\Providers\Groq\Maps\ToolMap;
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

it('relaxes boolean and number parameter types to also accept strings', function (): void {
    $tool = (new Tool)
        ->as('get_transactions')
        ->for('Fetches transactions')
        ->withNumberParameter('limit', 'maximum number of results')
        ->withBooleanParameter('include_pending', 'include pending transactions')
        ->withStringParameter('account', 'the account name')
        ->using(fn (): string => '[]');

    $properties = ToolMap::map([$tool])[0]['function']['parameters']['properties'];

    expect($properties->limit)->not->toHaveProperty('type')
        ->and($properties->limit->anyOf)->toBe([['type' => 'number'], ['type' => 'string']])
        ->and($properties->include_pending->anyOf)->toBe([['type' => 'boolean'], ['type' => 'string']])
        ->and($properties->account->type)->toBe('string');
});

it('maps tools with strict mode', function (): void {
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
