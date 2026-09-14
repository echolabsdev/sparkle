<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Support\JsonMap;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class ModerationResult implements Arrayable
{
    /**
     * @param  array<string, bool>  $categories
     * @param  array<string, float>  $categoryScores
     */
    public function __construct(
        public bool $flagged,
        public array $categories,
        public array $categoryScores,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            flagged: (bool) data_get($data, 'flagged', false),
            categories: data_get($data, 'categories', []) ?: [],
            categoryScores: data_get($data, 'category_scores', []) ?: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'flagged' => $this->flagged,
            'categories' => JsonMap::of($this->categories),
            'category_scores' => JsonMap::of($this->categoryScores),
        ];
    }
}
