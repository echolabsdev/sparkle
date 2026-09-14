<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Maps;

use Prism\Prism\Enums\FinishReason;

class FinishReasonMap
{
    public static function map(string $status): FinishReason
    {
        return match ($status) {
            'succeeded' => FinishReason::Stop,
            'failed', 'canceled' => FinishReason::Error,
            default => FinishReason::Unknown,
        };
    }
}
