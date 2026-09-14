<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\Providers\Anthropic\Concerns\NormalizesCacheControl;
use Prism\Prism\Tool as PrismTool;

class ToolMap
{
    use NormalizesCacheControl;

    /**
     * @param  PrismTool[]  $tools
     * @return array<string, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (PrismTool $tool): array => array_filter([
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => [
                'type' => 'object',
                'properties' => $tool->parametersAsObject(),
                'required' => $tool->requiredParameters(),
            ],
            'cache_control' => self::normalizeCacheControl($tool),
            'strict' => (bool) $tool->providerOptions('strict'),
            'eager_input_streaming' => (bool) $tool->providerOptions('eager_input_streaming'),
        ]), $tools);
    }
}
