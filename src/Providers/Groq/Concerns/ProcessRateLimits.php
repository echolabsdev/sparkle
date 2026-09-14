<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\Support\HeaderNames;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    /**
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(Response $response): array
    {
        // Folded first: the prefix below is matched case-sensitively against the
        // raw wire case, so a title-casing gateway used to filter every header
        // out and report no rate limits at all. See Anthropic's reader for the
        // same fix and the reason the fold is ASCII-only.
        $limitHeaders = array_filter(
            HeaderNames::folded($response->getHeaders()),
            fn ($headerName) => Str::startsWith($headerName, 'x-ratelimit-'),
            ARRAY_FILTER_USE_KEY
        );

        $rateLimits = [];

        foreach ($limitHeaders as $headerName => $headerValues) {
            $limitName = Str::of($headerName)->afterLast('-')->toString();
            $fieldName = Str::of($headerName)->after('x-ratelimit-')->beforeLast('-')->toString();

            $rateLimits[$limitName][$fieldName] = $headerValues[0];
        }

        return array_values(Arr::map($rateLimits, function ($fields, $limitName): ProviderRateLimit {
            $resetsAt = data_get($fields, 'reset', '');

            if (str_contains($resetsAt, 'ms')) {
                $resetMilliseconds = Str::of($resetsAt)->before('ms')->toString();
                $resetMinutes = 0;
                $resetSeconds = 0;
            } else {
                $resetMilliseconds = 0;

                $resetMinutes = str_contains($resetsAt, 'm')
                    ? Str::of($resetsAt)->before('m')->toString()
                    : 0;

                $resetSeconds = str_contains($resetsAt, 'm')
                    ? Str::of($resetsAt)->after('m')->before('s')->toString()
                    : Str::of($resetsAt)->before('s')->toString();
            }

            return new ProviderRateLimit(
                name: $limitName,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: data_get($fields, 'reset') !== null
                    ? Carbon::now()->addMinutes((int) $resetMinutes)->addSeconds((int) $resetSeconds)->addMilliseconds((int) $resetMilliseconds)
                    : null
            );
        }));
    }
}
