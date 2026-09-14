<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
class Embedding implements Arrayable
{
    /**
     * @param  array<int, int|string|float>  $embedding
     */
    public function __construct(
        public array $embedding
    ) {}

    /**
     * Accepts either a bare vector or the shape `toArray()` produces.
     *
     * The two did not compose: `toArray()` satisfies Arrayable and wraps the
     * vector under an `embedding` key, while this took the bare list. So the
     * obvious round trip built an embedding whose components were a single
     * nested array — which does not fail here, it fails at the first
     * arithmetic somewhere else entirely.
     *
     * The shapes are unambiguous (a wrapper has the string key; a vector has
     * integer keys), so accepting both costs nothing and removes a trap that
     * a caller can only find by hitting it.
     *
     * @param  array<int, int|string|float>|array{embedding: array<int, int|string|float>}  $embedding
     */
    public static function fromArray(array $embedding): self
    {
        if (array_key_exists('embedding', $embedding) && is_array($embedding['embedding'])) {
            return new self(embedding: $embedding['embedding']);
        }

        /** @var array<int, int|string|float> $embedding */
        return new self(embedding: $embedding);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'embedding' => $this->embedding,
        ];
    }
}
