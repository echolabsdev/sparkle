<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

use Prism\Prism\Text\Request as TextRequest;

readonly class BatchRequestItem
{
    public function __construct(
        public string $customId,
        public TextRequest $request,
    ) {}
}
