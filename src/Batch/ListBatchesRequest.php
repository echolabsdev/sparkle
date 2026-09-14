<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

use Closure;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\HasProviderOptions;

class ListBatchesRequest
{
    use ConfiguresClient;
    use HasProviderOptions;

    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?string $afterId = null,
        public readonly ?string $beforeId = null,
    ) {}

    /**
     * @return array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}
     */
    public function clientRetry(): array
    {
        return $this->clientRetry;
    }

    /**
     * @return array<string, mixed>
     */
    public function clientOptions(): array
    {
        return $this->clientOptions;
    }
}
