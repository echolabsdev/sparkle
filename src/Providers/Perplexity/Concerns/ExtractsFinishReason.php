<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Enums\FinishReason;

trait ExtractsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasFinishReason(array $data): bool
    {
        return data_get($data, 'status') !== null;
    }

    /**
     * The Agent API reports a run status rather than a per-choice finish
     * reason. A failed or cancelled run never reaches here — assertRunSucceeded
     * throws first — so this maps the states that can arrive on a live read.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsFinishReason(array $data): FinishReason
    {
        return match (data_get($data, 'status')) {
            'completed' => FinishReason::Stop,
            'incomplete' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
