<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Prism\Prism\Support\JsonMap;

readonly class Artifact
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $data,
        public string $mimeType,
        public array $metadata = [],
        public ?string $id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'mime_type' => $this->mimeType,
            'metadata' => JsonMap::of($this->metadata),
        ];
    }

    public function rawContent(): string
    {
        return base64_decode($this->data);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromRawContent(
        string $content,
        string $mimeType,
        array $metadata = [],
        ?string $id = null,
    ): self {
        return new self(
            data: base64_encode($content),
            mimeType: $mimeType,
            metadata: $metadata,
            id: $id,
        );
    }
}
