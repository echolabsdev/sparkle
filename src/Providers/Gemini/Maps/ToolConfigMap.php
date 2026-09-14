<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Enums\ToolChoice;

class ToolConfigMap
{
    /**
     * @return array<string, mixed>|null
     */
    public static function map(string|ToolChoice|null $toolChoice, bool $includeServerSideToolInvocations = false): ?array
    {
        $config = ToolChoiceMap::map($toolChoice);

        /** @var array<string, mixed>|null $config */
        $config = is_array($config) ? $config : null;

        if ($includeServerSideToolInvocations) {
            return array_merge($config ?? [], ['includeServerSideToolInvocations' => true]);
        }

        return $config;
    }
}
