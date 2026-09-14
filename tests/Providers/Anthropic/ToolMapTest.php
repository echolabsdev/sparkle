<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\Providers\Anthropic\Maps\ToolMap;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toEqual([[
        'name' => 'search',
        'description' => 'Searching the web',
        'input_schema' => [
            'type' => 'object',
            'properties' => (object) [
                'query' => (object) [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ],
    ]]);
});

it('sets the cache typeif cacheType providerOptions is set on tool', function (mixed $cacheType): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
        ->withProviderOptions(['cacheType' => $cacheType, 'cacheTtl' => '1h']);

    expect(ToolMap::map([$tool]))->toEqual([[
        'name' => 'search',
        'description' => 'Searching the web',
        'input_schema' => [
            'type' => 'object',
            'properties' => (object) [
                'query' => (object) [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ],
        'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
    ]]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral->value,
]);

it('sets eager_input_streaming when eager_input_streaming provider option is true on tool', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
        ->withProviderOptions(['eager_input_streaming' => true]);

    expect(ToolMap::map([$tool]))->toEqual([[
        'name' => 'search',
        'description' => 'Searching the web',
        'input_schema' => [
            'type' => 'object',
            'properties' => (object) [
                'query' => (object) [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ],
        'eager_input_streaming' => true,
    ]]);
});

it('omits eager_input_streaming when eager_input_streaming provider option is omitted or false', function (?bool $eagerInputStreaming): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    if ($eagerInputStreaming !== null) {
        $tool = $tool->withProviderOptions(['eager_input_streaming' => $eagerInputStreaming]);
    }

    expect(ToolMap::map([$tool])[0])->not->toHaveKey('eager_input_streaming');
})->with([
    'omitted' => [null],
    'false' => [false],
]);
