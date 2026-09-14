<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies;

use Illuminate\Http\Client\Response as HttpResponse;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\ResolvesThinking;
use Prism\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Structured\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ToolStructuredStrategy extends AnthropicStructuredStrategy
{
    use ResolvesThinking;

    public const STRUCTURED_OUTPUT_TOOL_NAME = 'output_structured_data';

    public function appendMessages(): void
    {
        if ($this->request->providerOptions('thinking.enabled') === false) {
            return;
        }

        $this->request->addMessage(new UserMessage(sprintf(
            "Please use the %s tool to provide your response. If for any reason you cannot use the tool, respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s",
            self::STRUCTURED_OUTPUT_TOOL_NAME,
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutatePayload(array $payload): array
    {
        $schemaArray = $this->request->schema()->toArray();

        $structuredOutputTool = [
            'name' => self::STRUCTURED_OUTPUT_TOOL_NAME,
            'description' => 'Output data in the requested structure',
            'input_schema' => [
                'type' => 'object',
                'properties' => $schemaArray['properties'],
                'required' => $schemaArray['required'] ?? [],
                'additionalProperties' => false,
            ],
        ];

        $existingTools = $payload['tools'] ?? [];

        $payload = [
            ...$payload,
            'tools' => [...$existingTools, $structuredOutputTool],
        ];

        $toolChoice = $this->resolveToolChoice();
        if ($toolChoice !== null) {
            $payload['tool_choice'] = $toolChoice;
        } else {
            unset($payload['tool_choice']);
        }

        return $payload;
    }

    public function mutateResponse(HttpResponse $httpResponse, PrismResponse $prismResponse): PrismResponse
    {
        $structured = [];
        $additionalContent = $prismResponse->additionalContent;

        $data = $httpResponse->json();

        $toolCalls = array_values(array_filter(
            data_get($data, 'content', []),
            fn ($content): bool => data_get($content, 'type') === 'tool_use' && data_get($content, 'name') === self::STRUCTURED_OUTPUT_TOOL_NAME
        ));

        $structured = data_get($toolCalls, '0.input', []);

        return new PrismResponse(
            steps: $prismResponse->steps,
            text: $prismResponse->text,
            structured: $structured,
            finishReason: $prismResponse->finishReason,
            usage: $prismResponse->usage,
            meta: $prismResponse->meta,
            additionalContent: $additionalContent
        );
    }

    /**
     * @return array<string, mixed>|string|null
     */
    protected function resolveToolChoice(): string|array|null
    {
        // Thinking mode doesn't support tool_choice (Anthropic restriction).
        // Any thinking shape counts, not only Prism's spellings: a thinking
        // shape is sent as given, and forcing a tool beside it is a 400.
        if (static::thinkingIsOn($this->request)) {
            return null;
        }

        $userToolChoice = $this->request->toolChoice();
        $hasCustomTools = $this->request->tools() !== [];

        // No custom tools: force the structured output tool
        if (! $hasCustomTools) {
            return ['type' => 'tool', 'name' => self::STRUCTURED_OUTPUT_TOOL_NAME];
        }

        // Custom tools present with explicit user choice: map the user's choice
        if ($userToolChoice !== null) {
            return ToolChoiceMap::map($userToolChoice);
        }

        // Custom tools present with no explicit choice: use auto
        return ['type' => 'auto'];
    }

    protected function checkStrategySupport(): void
    {
        if ($this->request->providerOptions('citations') === true) {
            throw new PrismException(
                'Citations are not supported with tool calling mode. Please set use_tool_calling to false in provider options to use citations.'
            );
        }

        if ($this->request->toolChoice() === ToolChoice::None) {
            throw new PrismException(
                'ToolChoice::None is incompatible with tool-based structured output. Use JSON mode (set use_tool_calling to false) or choose a different tool choice option.'
            );
        }
    }
}
