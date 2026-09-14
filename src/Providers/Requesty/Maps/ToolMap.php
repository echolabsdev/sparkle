<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  array<int, Tool>  $tools
     * @return array<int, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => array_filter([
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                ...$tool->hasParameters() ? [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool->parametersAsObject(),
                        'required' => $tool->requiredParameters(),
                    ],
                ] : [],
            ],
            'strict' => $tool->providerOptions('strict'),
        ]), $tools);
    }
}
