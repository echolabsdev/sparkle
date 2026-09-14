<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Illuminate\Support\Arr;

trait ExtractsReasoning
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractReasoning(array $data): array
    {
        return Arr::whereNotNull([
            'reasoning' => data_get($data, 'choices.0.message.reasoning'),
            'reasoning_details' => data_get($data, 'choices.0.message.reasoning_details'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractThoughtTokens(array $data): ?int
    {
        $tokens = data_get($data, 'usage.completion_tokens_details.reasoning_tokens');

        return $tokens !== null ? (int) $tokens : null;
    }
}
