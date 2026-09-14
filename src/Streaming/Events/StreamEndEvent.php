<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

readonly class StreamEndEvent extends StreamEvent
{
    /**
     * @param  array<MessagePartWithCitations>|null  $citations
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        string $id,
        int $timestamp,
        public FinishReason $finishReason,  // Why stream ended
        public ?Usage $usage = null,        // Token usage information
        public ?array $citations = null,    // Citations collected during stream
        public array $additionalContent = []
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::StreamEnd;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->additionalContent,
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'finish_reason' => $this->finishReason->name,
            'usage' => $this->usage?->toArray(),
            'citations' => $this->citations !== null ? array_map(
                fn (MessagePartWithCitations $citationPart): array => [
                    'output_text' => $citationPart->outputText,
                    'citations' => array_map(
                        fn (Citation $citation): array => [
                            'source_type' => $citation->sourceType->value,
                            'source' => $citation->source,
                            'source_text' => $citation->sourceText,
                            'source_title' => $citation->sourceTitle,
                            'source_position_type' => $citation->sourcePositionType?->value,
                            'source_start_index' => $citation->sourceStartIndex,
                            'source_end_index' => $citation->sourceEndIndex,
                            'additional_content' => $citation->additionalContent,
                        ],
                        $citationPart->citations
                    ),
                ],
                $this->citations
            ) : null,
        ];
    }
}
