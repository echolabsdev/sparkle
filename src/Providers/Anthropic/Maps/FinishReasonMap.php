<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\Enums\FinishReason;

class FinishReasonMap
{
    public static function map(string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'pause_turn' => FinishReason::Pause,
            'refusal' => FinishReason::Refusal,
            default => FinishReason::Unknown,
        };
    }
}
