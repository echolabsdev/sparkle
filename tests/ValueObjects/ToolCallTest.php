<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\ToolCall;

it('handles empty string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: ''
    );

    expect($toolCall->arguments)->toBe('');
    expect($toolCall->arguments())->toBe([]);
});

it('handles null arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: []
    );

    expect($toolCall->arguments)->toBe([]);
    expect($toolCall->arguments())->toBe([]);
});

it('handles empty object arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{}'
    );

    expect($toolCall->arguments)->toBe('{}');
    expect($toolCall->arguments())->toBe([]);
});

it('handles valid JSON string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"param1": "value1", "param2": 42}'
    );

    expect($toolCall->arguments)->toBe(
        '{"param1": "value1", "param2": 42}'
    );

    expect($toolCall->arguments())->toBe([
        'param1' => 'value1',
        'param2' => 42,
    ]);
});

it('handles array arguments correctly', function (): void {
    $arguments = ['param1' => 'value1', 'param2' => 42];

    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: $arguments
    );

    expect($toolCall->arguments)->toBe($arguments);
    expect($toolCall->arguments())->toBe($arguments);
});

it('escapes raw control characters inside string values instead of dropping them', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: "{\"code\": \"line one\nline two\tindented\"}"
    );

    expect($toolCall->arguments())->toBe([
        'code' => "line one\nline two\tindented",
    ]);
});

it('drops invalid control characters outside string values', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: "{\"param\":\x01 \"value\"}"
    );

    expect($toolCall->arguments())->toBe(['param' => 'value']);
});

it('does not mangle escape sequences already present in valid JSON', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"text": "a\\nb \\"quoted\\" c\\\\d"}'
    );

    expect($toolCall->arguments())->toBe([
        'text' => "a\nb \"quoted\" c\\d",
    ]);
});

it('handles JSON null string arguments correctly', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: 'null'
    );

    expect($toolCall->arguments())->toBe([]);
});

it('throws a handled PrismException for malformed JSON string arguments', function (): void {
    $toolCall = new ToolCall(
        id: 'test-id',
        name: 'test-tool',
        arguments: '{"invalid json"'
    );

    expect($toolCall->arguments)->toBe('{"invalid json"');

    // Malformed arguments surface as a handled PrismException (wrapping the
    // underlying JsonException) so the tool-execution loop can turn it into a
    // tool result the model sees, rather than crashing with a raw exception.
    expect($toolCall->arguments(...))->toThrow(PrismException::class, 'not valid JSON');
});

it('sends an empty argument set as a JSON object, not a JSON array', function (): void {
    $toolCall = new ToolCall(id: 'call-1', name: 'now', arguments: []);

    expect($toolCall->arguments())->toBe([])
        ->and($toolCall->hasArguments())->toBeFalse()
        ->and($toolCall->argumentsAsJson())->toBe('{}')
        ->and(json_encode($toolCall->argumentsAsObject()))->toBe('{}');
});

it('does not change the bytes of a populated argument set', function (): void {
    $toolCall = new ToolCall(id: 'call-1', name: 'search', arguments: ['query' => 'laravel']);

    expect($toolCall->hasArguments())->toBeTrue()
        ->and($toolCall->argumentsAsJson())->toBe('{"query":"laravel"}');
});

it('normalises string arguments through the same accessor', function (): void {
    $toolCall = new ToolCall(id: 'call-1', name: 'search', arguments: '{"query":"laravel"}');

    expect($toolCall->argumentsAsJson())->toBe('{"query":"laravel"}')
        ->and((new ToolCall(id: 'call-2', name: 'now', arguments: ''))->argumentsAsJson())->toBe('{}');
});

it('serialises an empty argument set as an object in its stored form too', function (): void {
    // The wire and the stored form used to disagree: {} to the provider, []
    // in toArray(). A conversation persisted as JSON and read back in another
    // language saw the second one.
    $toolCall = new ToolCall(id: 'call-1', name: 'now', arguments: []);

    expect(json_encode($toolCall->toArray()['arguments']))->toBe('{}');
});

it('keeps raw string arguments as the string they arrived as', function (): void {
    $toolCall = new ToolCall(id: 'call-1', name: 'now', arguments: '{"a":1}');

    expect($toolCall->toArray()['arguments'])->toBe('{"a":1}');
});

it('preserves an empty object nested anywhere inside the arguments', function (): void {
    // The case no key-aware rule reaches: these are not fields Prism declares,
    // they are arbitrary JSON the model produced. Before the decode carried the
    // distinction, "filter" came back as [] and went to the provider that way.
    $toolCall = new ToolCall(
        id: 'call-1',
        name: 'search',
        arguments: '{"filter":{},"tags":[],"deep":{"a":{"b":{}}}}',
    );

    expect($toolCall->argumentsAsJson())->toBe('{"filter":{},"tags":[],"deep":{"a":{"b":{}}}}');
});

it('does not promote a list to an object, which the naive fix would', function (): void {
    // "required": [] is an entirely ordinary empty LIST. A rule that turned
    // every empty array into {} would trade one silent divergence for a commoner one.
    $toolCall = new ToolCall(id: 'call-1', name: 'declare', arguments: '{"required":[],"items":[]}');

    expect($toolCall->argumentsAsJson())->toBe('{"required":[],"items":[]}');
});

it('still hands PHP callers arrays, so a tool typed array keeps working', function (): void {
    $toolCall = new ToolCall(id: 'call-1', name: 'search', arguments: '{"filter":{},"deep":{"a":{}}}');

    expect($toolCall->arguments())->toBe(['filter' => [], 'deep' => ['a' => []]]);
});
