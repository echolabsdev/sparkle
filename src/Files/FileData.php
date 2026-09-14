<?php

declare(strict_types=1);

namespace Prism\Prism\Files;

readonly class FileData
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public string $id,
        public ?string $filename = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?string $createdAt = null,
        public ?string $purpose = null,
        public ?array $raw = null,
    ) {}
}
