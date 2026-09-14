<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Embedding;

it('rebuilds from its own toArray output', function (): void {
    // toArray() is the Arrayable contract and wraps the vector under a key.
    // fromArray() took the bare list. So the obvious round trip built an
    // embedding whose components were a single nested array — and that fails
    // at the first arithmetic, nowhere near where the mistake was made.
    $original = new Embedding([0.1, 0.2, 0.3]);

    $rebuilt = Embedding::fromArray($original->toArray());

    expect($rebuilt->embedding)->toBe([0.1, 0.2, 0.3]);
});

it('still accepts a bare vector', function (): void {
    // The pre-existing shape. Providers construct from a raw list and that
    // must keep working.
    expect(Embedding::fromArray([0.1, 0.2, 0.3])->embedding)->toBe([0.1, 0.2, 0.3]);
});

it('round-trips an empty vector without inventing a wrapper', function (): void {
    expect(Embedding::fromArray((new Embedding([]))->toArray())->embedding)->toBe([]);
});
