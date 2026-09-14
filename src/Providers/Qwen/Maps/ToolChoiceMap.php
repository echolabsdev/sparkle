<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Maps;

use InvalidArgumentException;
use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    public static function map(string|ToolChoice|null $toolChoice): ?string
    {
        if (is_string($toolChoice)) {
            throw new InvalidArgumentException('Qwen does not support forcing a specific tool. Only "auto" and "none" are supported.');
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            null => $toolChoice,
            default => throw new InvalidArgumentException('Invalid tool choice for Qwen. Only "auto" and "none" are supported.'),
        };
    }
}
