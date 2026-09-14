<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class ChatCompletionsCitationsMapper
{
    /**
     * Map citations and search_results from chat/completions responses
     * into Prism's citation format. Used by providers with integrated
     * search capabilities (e.g. Perplexity, You.com).
     *
     * @param  array<int, string>  $citations
     * @param  array<int, array<string, mixed>>  $searchResults
     */
    public static function map(array $citations, array $searchResults = []): ?MessagePartWithCitations
    {
        if ($citations === []) {
            return null;
        }

        $mapped = [];

        foreach ($citations as $index => $url) {
            $searchResult = $searchResults[$index] ?? [];

            $mapped[] = new Citation(
                sourceType: CitationSourceType::Url,
                source: $url,
                sourceText: $searchResult['snippet'] ?? null,
                sourceTitle: $searchResult['title'] ?? null,
                additionalContent: Payload::compact([
                    'date' => $searchResult['date'] ?? null,
                    'last_updated' => $searchResult['last_updated'] ?? null,
                    'source' => $searchResult['source'] ?? null,
                ]),
            );
        }

        return new MessagePartWithCitations(
            outputText: '',
            citations: $mapped,
        );
    }
}
