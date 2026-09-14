<?php

declare(strict_types=1);

use Prism\Prism\Support\Json;

it('is why this exists: an associative decode cannot tell the two apart', function (): void {
    expect(json_decode('{}', true))->toBe(json_decode('[]', true));
});

it('carries an empty object through a decode and back', function (): void {
    expect(json_encode(Json::decode('{}', preservingContainerTypes: true)))->toBe('{}');
});

it('leaves an empty list a list', function (): void {
    // The naive generalisation — promote every empty array — would make this
    // {} and break far more payloads than it fixed.
    expect(json_encode(Json::decode('{"required":[],"tags":[]}', preservingContainerTypes: true)))
        ->toBe('{"required":[],"tags":[]}');
});

it('reaches an empty object nested arbitrarily deep, which no key-aware rule does', function (): void {
    $raw = '{"a":{"b":{"c":{},"d":[{"e":{}}]}}}';

    expect(json_encode(Json::decode($raw, preservingContainerTypes: true)))->toBe($raw);
});

it('hands populated objects back as arrays, so callers see what they always saw', function (): void {
    expect(Json::decode('{"a":{"b":1},"c":[1,2]}', preservingContainerTypes: true))
        ->toBe(['a' => ['b' => 1], 'c' => [1, 2]]);
});

it('decodes associatively and loses the distinction when not asked to preserve it', function (): void {
    expect(Json::decode('{"a":{}}'))->toBe(['a' => []]);
});

it('throws rather than returning null on malformed input', function (): void {
    expect(fn (): mixed => Json::decode('{not json'))->toThrow(JsonException::class);
});
