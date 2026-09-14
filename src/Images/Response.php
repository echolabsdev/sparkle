<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Response implements Arrayable
{
    /**
     * @param  GeneratedImage[]  $images
     * @param  array<string,mixed>  $additionalContent
     * @param  array<string,mixed>|null  $raw
     */
    public function __construct(
        public array $images,
        public Usage $usage,
        public Meta $meta,
        public array $additionalContent = [],
        public ?array $raw = null
    ) {}

    public function firstImage(): ?GeneratedImage
    {
        return $this->images[0] ?? null;
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }

    public function imageCount(): int
    {
        return count($this->images);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'images' => array_map(fn (GeneratedImage $image): array => $image->toArray(), $this->images),
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'additional_content' => JsonMap::of($this->additionalContent),
            'raw' => $this->raw,
        ];
    }
}
