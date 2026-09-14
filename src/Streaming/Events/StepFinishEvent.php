<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\Usage;

readonly class StepFinishEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public ?Usage $usage = null,        // Token usage information
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::StepFinish;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'usage' => $this->usage instanceof Usage ? [
                'prompt_tokens' => $this->usage->promptTokens,
                'completion_tokens' => $this->usage->completionTokens,
                'cache_write_input_tokens' => $this->usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $this->usage->cacheReadInputTokens,
                'thought_tokens' => $this->usage->thoughtTokens,
            ] : null,
        ];
    }
}
