<?php

declare(strict_types=1);

use Prism\Prism\Support\JsonMap;

it('is why this exists: PHP renders the same map as two different JSON types', function (): void {
    // Not assumed — asserted, because the whole of JsonMap is downstream of it.
    expect(json_encode([]))->toBe('[]')
        ->and(json_encode(['a' => 1]))->toBe('{"a":1}');
});

it('renders an empty map as a JSON object', function (): void {
    expect(json_encode(JsonMap::of([])))->toBe('{}')
        ->and(JsonMap::encode([]))->toBe('{}');
});

it('renders a populated map as the same object it always was', function (): void {
    expect(json_encode(JsonMap::of(['a' => 1, 'b' => ['c' => 2]])))->toBe('{"a":1,"b":{"c":2}}')
        ->and(JsonMap::encode(['a' => 1]))->toBe('{"a":1}');
});

it('keeps a numeric-keyed map an object, which is the case a bare array loses', function (): void {
    // ['0' => 'x'] is a list to PHP and an object to every JSON consumer.
    expect(json_encode(['0' => 'x']))->toBe('["x"]')
        ->and(JsonMap::encode(['0' => 'x']))->toBe('{"0":"x"}');
});

it('leaves nested lists alone', function (): void {
    expect(JsonMap::encode(['tags' => []]))->toBe('{"tags":[]}');
});
