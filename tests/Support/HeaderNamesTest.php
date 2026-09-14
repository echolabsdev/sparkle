<?php

declare(strict_types=1);

use Prism\Prism\Support\HeaderNames;

it('folds an ASCII field name the way HTTP compares one', function (): void {
    expect(HeaderNames::fold('Anthropic-RateLimit-Requests-Limit'))
        ->toBe('anthropic-ratelimit-requests-limit');
});

it('re-keys a header map and leaves the values alone', function (): void {
    expect(HeaderNames::folded([
        'X-RateLimit-Limit-Tokens' => ['200000'],
        'content-type' => ['application/json'],
    ]))->toBe([
        'x-ratelimit-limit-tokens' => ['200000'],
        'content-type' => ['application/json'],
    ]);
});

it('keeps the order the headers arrived in', function (): void {
    // Anthropic's reader names its buckets in header order, so a fold that
    // re-ordered the map would change which bucket a caller reading
    // `rateLimits[0]` gets — a divergence nothing errors on.
    expect(array_keys(HeaderNames::folded([
        'B-Second' => ['2'],
        'A-First' => ['1'],
    ])))->toBe(['b-second', 'a-first']);
});

it('will not fold a lookalike codepoint into a real bucket name', function (): void {
    // THE REASON THIS IS NOT mb_strtolower(). U+212A KELVIN SIGN folds to a
    // plain ASCII `k` under a Unicode-aware fold, so `anthropic-ratelimit-
    // to<U+212A>ens-limit` would come back as a bucket named `tokens` — the
    // name a caller matches on to decide whether it has token quota left,
    // manufactured from a header Anthropic never sent.
    //
    // An HTTP field name is an RFC 9110 `token`: ASCII by grammar. A name
    // carrying this codepoint is a DIFFERENT name, and the fold keeps it one.
    $name = "anthropic-ratelimit-to\u{212A}ens-limit";

    expect(HeaderNames::fold($name))->toBe($name);
    expect(mb_strtolower($name))->toBe('anthropic-ratelimit-tokens-limit');
});

it('never changes the length of a name, which a Unicode fold does', function (): void {
    // U+0130 LATIN CAPITAL LETTER I WITH DOT ABOVE folds to TWO codepoints
    // (`i` + U+0307) under a Unicode-aware fold, and to a bare `i` under a
    // Turkish locale. The bucket name derived from the header would then be a
    // different string in each of the three languages that implement this
    // parser — a cross-language divergence produced by the fix rather than
    // removed by it. `strtr()` over 26 ASCII letters is reproducible byte for
    // byte in PHP, TypeScript and Python.
    $name = "anthropic-ratelimit-\u{0130}nput-tokens-limit";

    expect(strlen(HeaderNames::fold($name)))->toBe(strlen($name));
    expect(strlen(mb_strtolower($name)))->toBeGreaterThan(strlen($name));
});
