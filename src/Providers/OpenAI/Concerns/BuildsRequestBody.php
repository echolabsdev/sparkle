<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;

trait BuildsRequestBody
{
    use BuildsTools;

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestBody(Request $request): array
    {
        return array_merge([
            'model' => $request->model(),
            'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_output_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'tools' => $this->buildTools($request) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'service_tier' => $request->providerOptions('service_tier'),
            'store' => $request->providerOptions('store'),
            'text' => $request->providerOptions('text_verbosity') ? [
                'verbosity' => $request->providerOptions('text_verbosity'),
            ] : null,
            'truncation' => $request->providerOptions('truncation'),
            'reasoning' => $request->providerOptions('reasoning') ?? (
                $request->reasoningEnabled() === false ? ['effort' => 'minimal'] : null
            ),
        ]));
    }
}
