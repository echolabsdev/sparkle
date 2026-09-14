<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationsMapper
{
    /**
     * @param  array<string, mixed>  $contentBlock
     */
    public static function mapFromAnthropic(array $contentBlock): ?MessagePartWithCitations
    {
        if (! isset($contentBlock['type']) || $contentBlock['type'] !== 'text') {
            return null;
        }

        $citations = array_map(
            self::mapCitationFromAnthropic(...),
            $contentBlock['citations'] ?? []
        );

        return new MessagePartWithCitations(
            outputText: $contentBlock['text'] ?? '',
            citations: $citations,
        );
    }

    /**
     * Convert MessagePartWithCitations back to Anthropic API format
     *
     * @return array<string, mixed>
     */
    public static function mapToAnthropic(MessagePartWithCitations $messagePartWithCitations): array
    {
        $citations = array_map(
            self::mapCitationToAnthropic(...),
            $messagePartWithCitations->citations
        );

        return Payload::compact([
            'type' => 'text',
            'text' => $messagePartWithCitations->outputText,
            'citations' => $citations ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    public static function mapCitationFromAnthropic(array $citationData): Citation
    {
        $sourceType = self::mapSourceType($citationData['type']);
        $source = self::mapSource($citationData, $sourceType);
        $sourcePositionType = self::mapSourcePositionType($citationData['type']);

        $indices = self::mapIndices($citationData);
        $startIndex = $indices['start'] ?? null;
        $endIndex = $indices['end'] ?? null;

        return new Citation(
            sourceType: $sourceType,
            source: $source,
            sourceText: $citationData['cited_text'] ?? null,
            sourceTitle: $citationData['document_title'] ?? $citationData['title'] ?? null,
            sourcePositionType: $sourcePositionType,
            sourceStartIndex: $startIndex,
            sourceEndIndex: $endIndex,
            additionalContent: Arr::whereNotNull([
                'encrypted_index' => data_get($citationData, 'encrypted_index'),
            ])
        );
    }

    protected static function mapSourceType(string $anthropicType): CitationSourceType
    {
        return match ($anthropicType) {
            'web_search_result_location' => CitationSourceType::Url,
            'page_location', 'char_location', 'content_block_location' => CitationSourceType::Document,
            default => throw new InvalidArgumentException("Unknown citation type: {$anthropicType}"),
        };
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    protected static function mapSource(array $citationData, CitationSourceType $sourceType): string|int
    {
        if ($sourceType === CitationSourceType::Url) {
            return $citationData['url'] ?? '';
        }

        return $citationData['document_index'] ?? 0;
    }

    protected static function mapSourcePositionType(string $anthropicType): ?CitationSourcePositionType
    {
        return match ($anthropicType) {
            'page_location' => CitationSourcePositionType::Page,
            'char_location' => CitationSourcePositionType::Character,
            'content_block_location' => CitationSourcePositionType::Chunk,
            'web_search_result_location' => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $citationData
     * @return array{start:int|null,end:int|null}
     */
    protected static function mapIndices(array $citationData): array
    {
        $indexPropertyCommonPart = match ($citationData['type']) {
            'page_location' => 'page_number',
            'char_location' => 'char_index',
            'content_block_location' => 'block_index',
            'web_search_result_location' => null,
            default => null,
        };

        if ($indexPropertyCommonPart === null) {
            return ['start' => null, 'end' => null];
        }

        return [
            'start' => $citationData["start_$indexPropertyCommonPart"] ?? null,
            'end' => $citationData["end_$indexPropertyCommonPart"] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapCitationToAnthropic(Citation $citation): array
    {
        $anthropicType = self::mapSourcePositionTypeToAnthropic($citation->sourcePositionType);
        $indices = self::mapIndicesToAnthropic($citation, $anthropicType);

        $result = [
            'type' => $anthropicType,
            'cited_text' => $citation->sourceText,
        ];

        // Add document_index or url based on source type
        if ($citation->sourceType === CitationSourceType::Document) {
            $result['document_index'] = $citation->source;

            $result['document_title'] = $citation->sourceTitle;
        }
        if ($citation->sourceType === CitationSourceType::Url) {
            $result['url'] = $citation->source;

            $result['title'] = $citation->sourceTitle;
        }

        if ($index = data_get($citation->additionalContent, 'encrypted_index')) {
            $result['encrypted_index'] = $index;
        }

        $result = array_merge($result, $indices);

        return array_filter($result, fn ($value): bool => $value !== null && $value !== '');
    }

    protected static function mapSourcePositionTypeToAnthropic(?CitationSourcePositionType $sourcePositionType): string
    {
        return match ($sourcePositionType) {
            CitationSourcePositionType::Page => 'page_location',
            CitationSourcePositionType::Character => 'char_location',
            CitationSourcePositionType::Chunk => 'content_block_location',
            null => 'web_search_result_location',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapIndicesToAnthropic(Citation $citation, string $anthropicType): array
    {
        $indexPropertyCommonPart = match ($anthropicType) {
            'page_location' => 'page_number',
            'char_location' => 'char_index',
            'content_block_location' => 'block_index',
            'web_search_result_location' => null,
            default => null,
        };

        if ($indexPropertyCommonPart === null) {
            return [];
        }

        // Already correct: an explicit null-only callback, so a 0 index survives.
        return array_filter([
            "start_$indexPropertyCommonPart" => $citation->sourceStartIndex,
            "end_$indexPropertyCommonPart" => $citation->sourceEndIndex,
        ], fn (?int $value): bool => $value !== null);
    }
}
