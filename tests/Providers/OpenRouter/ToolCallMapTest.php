<?php

declare(strict_types=1);

namespace Tests\Providers\OpenRouter;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenRouter\Maps\ToolCallMap;

it('maps well-formed tool call arguments', function (): void {
    $toolCalls = ToolCallMap::map([
        [
            'id' => 'call_1',
            'function' => [
                'name' => 'get_weather',
                'arguments' => '{"city":"Detroit"}',
            ],
        ],
    ]);

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls[0]->name)->toBe('get_weather')
        ->and($toolCalls[0]->arguments())->toBe(['city' => 'Detroit']);
});

it('does not decode eagerly, so malformed JSON surfaces as a handled PrismException', function (): void {
    $toolCalls = ToolCallMap::map([
        [
            'id' => 'call_1',
            'function' => [
                'name' => 'get_weather',
                'arguments' => '{"city":"Detroit"',
            ],
        ],
    ]);

    // Mapping itself must not throw a raw TypeError (previously json_decode
    // returned null and the ToolCall constructor rejected it).
    expect($toolCalls[0]->name)->toBe('get_weather');

    // Decoding happens lazily via arguments(); malformed JSON is a handled
    // Prism error, not a raw PHP TypeError.
    expect(fn (): array => $toolCalls[0]->arguments())
        ->toThrow(PrismException::class, 'not valid JSON');
});

it('treats missing arguments as an empty argument list', function (): void {
    $toolCalls = ToolCallMap::map([
        [
            'id' => 'call_1',
            'function' => [
                'name' => 'ping',
            ],
        ],
    ]);

    expect($toolCalls[0]->arguments())->toBe([]);
});
