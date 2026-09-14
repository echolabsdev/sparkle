<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

trait ExtractsCitations
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<int, MessagePartWithCitations>|null
     */
    protected function extractCitations(array $data): ?array
    {
        // `content.*.citations` yields one entry PER CONTENT BLOCK, and that
        // entry is null for a block carrying no citations -- so for an ordinary
        // answer this was `[null]`, never `[]`, and the guard below never fired.
        //
        // The effect was that EVERY Anthropic text response came back with an
        // additionalContent['citations'] holding a MessagePartWithCitations per
        // block, each with an empty citation list and a duplicate of the output
        // text. Callers could not distinguish "this answer cites sources" from
        // "this answer exists"; the only response that escaped was one with no
        // content at all.
        //
        // Found by the prism-parity anthropic-text-response suite, on its first
        // run: prism-ts and prism-py both returned an empty additionalContent
        // and agreed with each other, which is the shape that says the odd one
        // out is here. OpenAI's ExtractsCitations reads a single block's
        // `annotations` with no wildcard, so it never had this.
        $citationsPerBlock = array_filter(
            data_get($data, 'content.*.citations', []),
            fn ($citations): bool => is_array($citations) && $citations !== []
        );

        if ($citationsPerBlock === []) {
            return null;
        }

        return array_values(Arr::whereNotNull(
            Arr::map(data_get($data, 'content', []), CitationsMapper::mapFromAnthropic(...))
        ));
    }
}
