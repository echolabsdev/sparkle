<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<int, mixed>  $toolCalls
     * @return array<int, ToolCall>
     */
    public static function map(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['function']['name'],
            // The raw string, not a decode of it. ToolCall decodes for PHP
            // callers and separately for the wire, and only the second keeps
            // the container types the model chose — a decode here throws them
            // away before the value object can see them.
            arguments: (string) ($toolCall['function']['arguments'] ?? ''),
        ), $toolCalls);
    }
}
