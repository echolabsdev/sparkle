<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  array<Tool>  $tools
     * @return array<array<string, mixed>>
     */
    public static function map(array $tools): array
    {
        if ($tools === []) {
            return [];
        }

        return array_map(fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            ...$tool->hasParameters() ? [
                'parameters' => [
                    'type' => 'object',
                    'properties' => JsonMap::ofMaps(self::mapProperties($tool->parameters())),
                    'required' => $tool->requiredParameters(),
                ],
            ] : [],
        ], $tools);
    }

    /**
     * @param  array<string,Schema>  $properties
     * @return array<string,mixed>
     */
    public static function mapProperties(array $properties): array
    {
        return Arr::mapWithKeys($properties, fn (Schema $schema, string $name): array => [
            $name => (new SchemaMap($schema))->toArray(),
        ]);
    }
}
