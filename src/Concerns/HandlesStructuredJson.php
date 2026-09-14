<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HandlesStructuredJson
{
    /**
     * @return array<string, mixed>
     */
    protected function extractStructuredData(string $text): array
    {
        if ($text === '') {
            return [];
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        // Valid JSON that is not an object/array — "0", "12", "\"text\"", "true" —
        // decodes to a scalar, which this method is declared to never return.
        return is_array($decoded) ? $decoded : [];
    }
}
