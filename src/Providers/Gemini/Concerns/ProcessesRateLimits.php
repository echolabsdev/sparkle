<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Illuminate\Support\Carbon;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessesRateLimits
{
    /**
     * @param  array<string, mixed>  $responseData
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(array $responseData): array
    {
        $quotaViolations = data_get($this->responseDetail($responseData, 'QuotaFailure'), 'violations', []);

        if (! is_array($quotaViolations)) {
            return [];
        }

        $resetsAt = $this->buildResetsAtFromResponse($responseData);

        return collect($quotaViolations)
            ->filter(fn (mixed $violation): bool => is_array($violation))
            ->map(fn (array $violation): ProviderRateLimit => new ProviderRateLimit(
                name: (string) data_get($violation, 'quotaId', data_get($violation, 'quotaMetric', 'quota')),
                limit: $this->toNullableInt(data_get($violation, 'quotaValue')),
                remaining: null,
                resetsAt: $resetsAt
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    protected function extractRetryAfterSeconds(array $responseData): ?int
    {
        $retryDelay = data_get($this->responseDetail($responseData, 'RetryInfo'), 'retryDelay');

        if (is_string($retryDelay) && preg_match('/^(?<seconds>\d+)(?:\.\d+)?s$/', $retryDelay, $matches) === 1) {
            return (int) $matches['seconds'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function buildResetsAtFromResponse(array $responseData): ?Carbon
    {
        $retryAfter = $this->extractRetryAfterSeconds($responseData);

        return $retryAfter === null ? null : Carbon::now()->addSeconds($retryAfter);
    }

    private function toNullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)
            ? (int) $value
            : null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function responseDetail(array $responseData, string $type): mixed
    {
        $details = data_get($responseData, 'error.details', []);

        return is_array($details)
            ? collect($details)->first(fn (mixed $detail): bool => data_get($detail, '@type') === "type.googleapis.com/google.rpc.$type")
            : null;
    }
}
