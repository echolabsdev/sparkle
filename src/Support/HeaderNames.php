<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

/**
 * Comparing an HTTP field name the way HTTP defines it: without regard to case.
 *
 * Field names are case-insensitive (RFC 9110 §5.1), and a gateway that
 * title-cases them is ordinary rather than hostile. Every rate-limit reader in
 * this package used to match its prefix case-sensitively against the raw
 * `getHeaders()` keys, so a single such proxy in front of the provider made
 * `processRateLimits()` return an EMPTY LIST — which is also exactly what a
 * response that legitimately carried no quota headers looks like. The failure
 * was therefore invisible when it happened and permanent afterwards.
 *
 * **The fold is deliberately ASCII-only, and that is the whole subtlety here.**
 * `mb_strtolower()` is Unicode-aware: `İ` (U+0130) folds to two codepoints, and
 * `K` (U+212A KELVIN SIGN) folds to a plain `k`. Either would let a name that is
 * NOT the field the provider sent be compared as though it were, and the
 * two-codepoint expansion also changes the string's length underneath an offset
 * arithmetic that assumes it did not. An HTTP field name is a `token` — ASCII by
 * grammar — so `strtr()` over the 26 ASCII letters is both the correct fold and
 * the only one the sibling ports can reproduce byte for byte. `strtolower()` is
 * ASCII-only from PHP 8.2 and locale-dependent before it; `strtr()` never was.
 */
class HeaderNames
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * One field name, folded to lower case as ASCII and nothing else.
     */
    public static function fold(string $name): string
    {
        return strtr($name, self::UPPER, self::LOWER);
    }

    /**
     * A header map re-keyed by folded name, values untouched.
     *
     * Later duplicates win, which is the same answer `getHeaderLine()` would
     * give for a name a server spelled two ways in one response.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $headers
     * @return array<string, TValue>
     */
    public static function folded(array $headers): array
    {
        $folded = [];

        foreach ($headers as $name => $value) {
            $folded[self::fold((string) $name)] = $value;
        }

        return $folded;
    }
}
