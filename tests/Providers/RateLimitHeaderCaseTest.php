<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits as AnthropicRateLimits;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits as GroqRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits as OpenAIRateLimits;

/**
 * Every rate-limit reader, against a gateway that title-cased the field names.
 *
 * Each reader is a `protected` trait method with no public surface, so the only
 * way to exercise the SHIPPED code rather than a copy of it is to compose the
 * trait and widen the method. THREE classes and not one: all three name the
 * method `processRateLimits`, so PHP refuses the composition outright — which is
 * worth noticing rather than aliasing away, because one name for three
 * genuinely different readers is exactly how one of them gets fixed and the
 * other two do not.
 */
final class AnthropicRateLimitReader
{
    use AnthropicRateLimits;

    public function read(Response $response): array
    {
        return $this->processRateLimits($response);
    }
}

final class OpenAIRateLimitReader
{
    use OpenAIRateLimits;

    public function read(Response $response): array
    {
        return $this->processRateLimits($response);
    }
}

final class GroqRateLimitReader
{
    use GroqRateLimits;

    public function read(Response $response): array
    {
        return $this->processRateLimits($response);
    }
}

function responseWithHeaders(array $headers): Response
{
    // A real Illuminate response over a real PSR-7 one, so the header CASE
    // survives exactly as a server sent it. Handing a reader a pre-normalised
    // array would answer this test in the test.
    return new Response(new PsrResponse(200, $headers, '{}'));
}

it('reads Anthropic quota through a title-casing gateway', function (): void {
    $limits = (new AnthropicRateLimitReader)->read(responseWithHeaders([
        'Anthropic-RateLimit-Requests-Limit' => '1000',
        'Anthropic-RateLimit-Requests-Remaining' => '500',
    ]));

    expect($limits)->toHaveCount(1);
    expect($limits[0]->name)->toBe('requests');
    expect($limits[0]->limit)->toBe(1000);
});

it('reads OpenAI quota through a title-casing gateway', function (): void {
    $limits = (new OpenAIRateLimitReader)->read(responseWithHeaders([
        'X-RateLimit-Limit-Tokens' => '200000',
        'X-RateLimit-Remaining-Tokens' => '199000',
        'X-RateLimit-Reset-Tokens' => '30s',
    ]));

    expect($limits)->toHaveCount(1);
    expect($limits[0]->name)->toBe('tokens');
    expect($limits[0]->limit)->toBe(200000);
    expect($limits[0]->resetsAt)->not->toBeNull();
});

it('reads Groq quota through a title-casing gateway', function (): void {
    $limits = (new GroqRateLimitReader)->read(responseWithHeaders([
        'X-RateLimit-Limit-Requests' => '14400',
        'X-RateLimit-Remaining-Requests' => '14399',
    ]));

    expect($limits)->toHaveCount(1);
    expect($limits[0]->name)->toBe('requests');
    expect($limits[0]->limit)->toBe(14400);
});

it('still reports nothing when the field name is not one of these', function (): void {
    // The control for the three above. An empty list has to keep meaning "the
    // response carried no quota headers", or the fix above would have traded an
    // invisible false negative for an invisible false positive.
    //
    // A lookalike name — U+212A KELVIN SIGN in place of a `k`, which a
    // Unicode-aware fold turns into a real bucket name — cannot be exercised
    // through this transport at all: Guzzle rejects any field name outside the
    // RFC 9110 `token` grammar before a reader ever sees it. That the fold is
    // nonetheless ASCII-only is asserted directly in tests/Support.
    $limits = (new OpenAIRateLimitReader)->read(responseWithHeaders([
        'X-Quota-Limit-Tokens' => '200000',
        'Content-Type' => 'application/json',
    ]));

    expect($limits)->toBe([]);
});
