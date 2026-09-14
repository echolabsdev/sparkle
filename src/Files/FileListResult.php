<?php

declare(strict_types=1);

namespace Prism\Prism\Files;

readonly class FileListResult
{
    /**
     * @param  FileData[]  $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore = false,
        public ?string $firstId = null,
        public ?string $lastId = null,
    ) {}
}
