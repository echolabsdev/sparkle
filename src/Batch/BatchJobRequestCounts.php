<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

readonly class BatchJobRequestCounts
{
    public function __construct(
        public int $processing = 0,
        public int $succeeded = 0,
        public int $failed = 0,
        public int $canceled = 0,
        public int $expired = 0,
        public int $total = 0,
    ) {}
}
