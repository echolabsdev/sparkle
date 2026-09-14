<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

use Prism\Prism\ValueObjects\Usage;

readonly class BatchResultItem
{
    public function __construct(
        public string $customId,
        public BatchResultStatus $status,
        public ?string $text = null,
        public ?Usage $usage = null,
        public ?string $messageId = null,
        public ?string $model = null,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
    ) {}
}
