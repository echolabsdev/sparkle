<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Tool;

class ChatCompletionsToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<string, mixed>|null
     */
    public static function map(array $tools): ?array
    {
        if ($tools === []) {
            return null;
        }

        return array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->parametersAsObject(),
                    'required' => $tool->requiredParameters(),
                ],
            ],
        ], $tools);
    }
}
