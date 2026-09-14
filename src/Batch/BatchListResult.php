<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

readonly class BatchListResult
{
    /**
     * @param  BatchJob[]  $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore = false,
        public ?string $lastId = null,
    ) {}
}
