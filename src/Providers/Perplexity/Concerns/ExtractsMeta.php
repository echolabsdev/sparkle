<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\ValueObjects\Meta;

trait ExtractsMeta
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractsMeta(array $data): Meta
    {
        // Defaulted rather than passed through: Meta types both as non-nullable
        // strings, so a response missing either one raises a TypeError inside a
        // value object instead of degrading. An absent id is not worth failing
        // a completed generation over, and the error it produced named Meta
        // rather than the provider that omitted the field.
        return new Meta(
            id: (string) (data_get($data, 'id') ?? ''),
            model: (string) (data_get($data, 'model') ?? ''),
            rateLimits: [],
        );
    }
}
