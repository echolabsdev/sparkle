<?php

declare(strict_types=1);

namespace Prism\Prism\Files;

readonly class DeleteFileResult
{
    public function __construct(
        public string $id,
        public bool $deleted,
    ) {}
}
