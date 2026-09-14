<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Prism\Prism\Support\HeaderNames;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    /**
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(Response $response): array
    {
        // `getHeaders()` keeps the case the wire carried, and these lookups are
        // exact array keys. HTTP field names are case-insensitive (RFC 9110
        // 5.1), so a gateway that title-cases them used to make every lookup
        // below miss and this method return an empty list -- indistinguishable
        // from a response that carried no quota headers at all.
        $headers = HeaderNames::folded($response->getHeaders());
        $rateLimits = [];

        // Process requests rate limit
        if (isset($headers['x-ratelimit-limit-requests']) && isset($headers['x-ratelimit-remaining-requests'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'requests',
                limit: (int) $headers['x-ratelimit-limit-requests'][0],
                remaining: (int) $headers['x-ratelimit-remaining-requests'][0],
                resetsAt: $this->parseResetTime($headers['x-ratelimit-reset-requests'][0] ?? null)
            );
        }

        // Process tokens rate limit
        if (isset($headers['x-ratelimit-limit-tokens']) && isset($headers['x-ratelimit-remaining-tokens'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'tokens',
                limit: (int) $headers['x-ratelimit-limit-tokens'][0],
                remaining: (int) $headers['x-ratelimit-remaining-tokens'][0],
                resetsAt: $this->parseResetTime($headers['x-ratelimit-reset-tokens'][0] ?? null)
            );
        }

        return $rateLimits;
    }

    protected function parseResetTime(?string $resetTime): ?Carbon
    {
        if (in_array($resetTime, [null, '', '0'], true)) {
            return null;
        }

        // Parse formats like "6ms", "30s", "5m", "1h"
        if (preg_match('/^(\d+)(ms|s|m|h)$/', $resetTime, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'ms' => Carbon::now()->addMilliseconds($value),
                's' => Carbon::now()->addSeconds($value),
                'm' => Carbon::now()->addMinutes($value),
                'h' => Carbon::now()->addHours($value),
            };
        }

        return null;
    }
}
