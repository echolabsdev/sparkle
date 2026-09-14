<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Maps;

use Prism\Prism\Support\JsonMap;
use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    public static function Map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => JsonMap::ofMaps(static::relaxTypes($tool->parametersAsArray())),
                    'required' => $tool->requiredParameters(),
                ],
            ],
        ], $tools);
    }

    /**
     * Llama models running on Groq consistently return all tool argument
     * values as JSON strings, even when the schema declares boolean or number.
     * Groq validates the model's output against our schema and rejects it:
     *
     *   Groq Error [400]: tool call validation failed: parameters for tool
     *   get_transactions did not match schema: `/limit`: expected number,
     *   but got string
     *
     * Wrapping non-string scalar types in anyOf accepts both the declared type
     * and a plain string, so Groq passes the response through to the caller
     * regardless of how the model serialised the value.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, array<string, mixed>>
     */
    protected static function relaxTypes(array $properties): array
    {
        return array_map(function (array $prop): array {
            $type = $prop['type'] ?? null;
            if (is_string($type) && in_array($type, ['boolean', 'number', 'integer'], true)) {
                unset($prop['type']);
                $prop['anyOf'] = [['type' => $type], ['type' => 'string']];
            }

            return $prop;
        }, $properties);
    }
}
