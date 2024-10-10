<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Drivers\Google;

use EchoLabs\Prism\Drivers\DriverTool;
use EchoLabs\Prism\Tool;

class GoogleTool extends DriverTool
{
    #[\Override]
    public static function toArray(Tool $tool): array
    {
        return [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => collect($tool->parameters())
                    ->keyBy('name')
                    ->map(fn (array $field): array => [
                        'type' => strtoupper($field['type']),
                        'description' => $field['description'],
                    ])
                    ->toArray(),
                'required' => $tool->requiredParameters(),
            ],
        ];
    }

    #[\Override]
    public static function map(array $tools): array
    {
        return [
            'functionDeclarations' => array_map(fn (Tool $tool): array => self::toArray($tool), $tools),
        ];
    }
}
