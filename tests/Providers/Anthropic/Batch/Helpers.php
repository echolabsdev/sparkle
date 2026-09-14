<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic\Batch;

use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Request as TextRequest;

function createTextRequest(string $prompt): TextRequest
{
    return Prism::text()
        ->using('anthropic', 'claude-sonnet-4-20250514')
        ->withPrompt($prompt)
        ->toRequest();
}

function createBatchRequest(int $count = 2): BatchRequest
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new BatchRequestItem(
            customId: "request-{$i}",
            request: createTextRequest("Hello {$i}"),
        );
    }

    return new BatchRequest(items: $items);
}
