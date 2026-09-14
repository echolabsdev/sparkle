<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\Support\HeaderNames;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessesRateLimits
{
    /**
     * @return array<int, ProviderRateLimit>
     */
    protected function processRateLimits(Response $response): array
    {
        $rate_limits = [];

        // Folded to lower case first. HTTP field names are case-insensitive
        // (RFC 9110 5.1), and `getHeaders()` hands back whatever case the wire
        // carried -- so an `Anthropic-RateLimit-...` from a title-casing gateway
        // used to match nothing and return an empty list, which is also what a
        // response with no quota headers looks like. Order survives the fold,
        // and order is part of the answer here.
        foreach (HeaderNames::folded($response->getHeaders()) as $headerName => $headerValues) {
            if (Str::startsWith($headerName, 'anthropic-ratelimit-') === false) {
                continue;
            }

            $limit_name = Str::of($headerName)->after('anthropic-ratelimit-')->beforeLast('-')->toString();
            $field_name = Str::of($headerName)->afterLast('-')->toString();
            $rate_limits[$limit_name][$field_name] = $headerValues[0];
        }

        return array_values(Arr::map($rate_limits, function ($fields, $limit_name): ProviderRateLimit {
            $resets_at = data_get($fields, 'reset');

            return new ProviderRateLimit(
                name: $limit_name,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: $this->parseResetsAt($resets_at)
            );
        }));
    }

    /**
     * The reset instant, or null when the header cannot be read as one.
     *
     * NEVER throws. `new Carbon('soon')` raises InvalidFormatException, and this
     * runs on the SUCCESS path -- after the model has answered and the call has
     * been billed. An unreadable rate-limit header would therefore destroy a
     * response the caller has already paid for, and turn a quota hint into an
     * outage.
     *
     * The header is not necessarily the provider's, either. Whatever proxy or
     * gateway sits in front of the API can set it, so the value is not trusted
     * input just because the response was a 200.
     *
     * Failing to null is what the sibling providers already do -- OpenAI returns
     * null when its duration regex does not match, and Gemini guards every parse
     * -- so this makes Anthropic consistent rather than inventing a policy. A
     * missing reset is a smaller loss than a lost response, and it is the same
     * value the caller gets from a provider that sends no reset header at all.
     */
    protected function parseResetsAt(mixed $resets_at): ?Carbon
    {
        if ($resets_at === null || $resets_at === '') {
            return null;
        }

        try {
            return is_numeric($resets_at)
                ? Carbon::createFromTimestamp((int) $resets_at)
                : new Carbon((string) $resets_at);
        } catch (\Throwable) {
            return null;
        }
    }
}
