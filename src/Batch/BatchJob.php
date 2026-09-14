<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

readonly class BatchJob
{
    /**
     * @param  list<array{code: string, message: string, line: int|null, param: string|null}>  $errors
     */
    public function __construct(
        public string $id,
        public BatchStatus $status,
        public BatchJobRequestCounts $requestCounts,
        public ?string $createdAt = null,
        public ?string $expiresAt = null,
        public ?string $endedAt = null,
        public ?string $resultsUrl = null,
        public ?string $inputFileId = null,
        public ?string $outputFileId = null,
        public ?string $errorFileId = null,
        public array $errors = [],
    ) {}
}
