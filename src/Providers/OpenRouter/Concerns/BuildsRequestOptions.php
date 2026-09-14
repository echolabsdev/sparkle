<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenRouter\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

trait BuildsRequestOptions
{
    /**
     * Assemble optional OpenRouter request parameters that Prism exposes via provider options.
     *
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(TextRequest|StructuredRequest $request, array $additional = []): array
    {
        // Keep OpenRouter option surface flexible; reference https://openrouter.ai/docs/api-reference/parameters for supported keys.
        $options = $request->providerOptions() ?? [];

        $options = array_merge($options, Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'max_tokens' => $request->maxTokens(),
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]));

        if ($request->reasoningEnabled() === false && ! isset($options['reasoning'])) {
            $options['reasoning'] = ['exclude' => true];
        }

        return Arr::whereNotNull(array_merge($options, $additional));
    }
}
