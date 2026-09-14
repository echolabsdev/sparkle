<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait ExtractsUsage
{
    /**
     * The Agent API names these input/output rather than prompt/completion.
     * The old keys are read as a fallback so a fixture or a proxy still
     * speaking the Sonar shape does not silently report zero tokens.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            promptTokens: (int) (data_get($data, 'usage.input_tokens')
                ?? data_get($data, 'usage.prompt_tokens')
                ?? 0),
            completionTokens: (int) (data_get($data, 'usage.output_tokens')
                ?? data_get($data, 'usage.completion_tokens')
                ?? 0),
            // Perplexity is one of only two providers that price a request in
            // its own response — the other being OpenRouter. Dropping it means
            // an application that could have had an exact figure falls back to
            // deriving one from a rate card, which is an estimate wearing the
            // same label.
            //
            // Nested rather than scalar here: `cost` is a breakdown, and
            // `total_cost` is the number that answers "what did this request
            // cost". The parts are left in the raw response for anyone who
            // wants input versus output versus request.
            cost: self::extractCost($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function extractCost(array $data): ?float
    {
        $cost = data_get($data, 'usage.cost.total_cost');

        // Zero is a real answer — a cached or free-tier request costs nothing,
        // and reporting null there would send the caller off to estimate a
        // figure the provider already gave them.
        return is_numeric($cost) ? (float) $cost : null;
    }
}
