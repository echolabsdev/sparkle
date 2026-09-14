<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Enums\ToolChoice;

class ChatCompletionsToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice): string|array|null
    {
        if (is_string($toolChoice)) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $toolChoice,
                ],
            ];
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::Any => 'required',
            ToolChoice::None => 'none',
            null => $toolChoice,
        };
    }
}
