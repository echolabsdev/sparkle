<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Support;

class Payload
{
    /**
     * Drop the keys a provider treats as absent — without dropping a literal "0".
     *
     * Message maps used a bare `array_filter()` here, which drops every falsy
     * value. That is right for a null `cache_control` or an empty `tool_calls`,
     * but it also silently removed `'content' => '0'`, because "0" is falsy in
     * PHP while being perfectly ordinary model output.
     *
     * This keeps the original intent — no nulls, no empty strings, no empty
     * arrays, no false — and keeps scalar zero, string or int.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function compact(array $payload): array
    {
        return array_filter(
            $payload,
            fn (mixed $value): bool => ! in_array($value, [null, '', [], false], true),
        );
    }
}
