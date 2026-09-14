<?php

declare(strict_types=1);

namespace Tests\Regression;

use Prism\Prism\Providers\Anthropic\Maps\MessageMap as AnthropicMessageMap;
use Prism\Prism\Providers\Azure\Maps\MessageMap as AzureMessageMap;
use Prism\Prism\Providers\Groq\Maps\MessageMap as GroqMessageMap;
use Prism\Prism\Providers\Mistral\Maps\MessageMap as MistralMessageMap;
use Prism\Prism\Providers\Ollama\Maps\MessageMap as OllamaMessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ChatCompletionsMessageMap;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap as OpenRouterMessageMap;
use Prism\Prism\Providers\Qwen\Maps\MessageMap as QwenMessageMap;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\Providers\XAI\Maps\MessageMap as XAIMessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * `array_filter()` with no callback drops every falsy value. The message maps
 * used it to strip a null cache_control or an empty tool_calls array, but it
 * also stripped 'content' => '0' — falsy in PHP, ordinary model output in
 * practice. Payload::compact() keeps the original intent and keeps "0".
 */
describe('Payload::compact', function (): void {
    it('keeps scalar zero in both string and int form', function (): void {
        expect(Payload::compact(['a' => '0', 'b' => 0]))
            ->toBe(['a' => '0', 'b' => 0]);
    });

    // These are what the bare array_filter was there to strip; that must not regress.
    it('still drops null, empty string, empty array and false', function (): void {
        expect(Payload::compact([
            'keep' => 'x',
            'null' => null,
            'empty_string' => '',
            'empty_array' => [],
            'false' => false,
        ]))->toBe(['keep' => 'x']);
    });
});

$assistantMaps = [
    'azure' => fn (AssistantMessage $m): array => (new AzureMessageMap([$m], []))(),
    'groq' => fn (AssistantMessage $m): array => (new GroqMessageMap([$m], []))(),
    'mistral' => fn (AssistantMessage $m): array => (new MistralMessageMap([$m], []))(),
    'ollama' => fn (AssistantMessage $m): array => (new OllamaMessageMap([$m]))->map(),
    'qwen' => fn (AssistantMessage $m): array => (new QwenMessageMap([$m], []))(),
    'xai' => fn (AssistantMessage $m): array => (new XAIMessageMap([$m], []))(),
    'openai chat completions' => fn (AssistantMessage $m): array => (new ChatCompletionsMessageMap([$m], []))(),
    'openrouter' => fn (AssistantMessage $m): array => (new OpenRouterMessageMap([$m], []))(),
];

foreach ($assistantMaps as $provider => $map) {
    it("keeps an assistant message whose content is exactly \"0\" ({$provider})", function () use ($map): void {
        $mapped = $map(new AssistantMessage('0'));

        $assistant = collect($mapped)->firstWhere('role', 'assistant');

        expect($assistant)->not->toBeNull()
            ->and($assistant)->toHaveKey('content');

        // OpenRouter nests content as [['type' => 'text', 'text' => …]].
        $content = is_array($assistant['content'])
            ? collect($assistant['content'])->pluck('text')->all()
            : [$assistant['content']];

        expect($content)->toContain('0');
    });

    it("still omits an empty tool_calls array ({$provider})", function () use ($map): void {
        $assistant = collect($map(new AssistantMessage('hi')))->firstWhere('role', 'assistant');

        expect($assistant)->not->toHaveKey('tool_calls');
    });
}

it('keeps a tool result whose content is exactly "0" (anthropic)', function (): void {
    $mapped = AnthropicMessageMap::map([
        new ToolResultMessage([
            new ToolResult(
                toolCallId: 'call_1',
                toolName: 'count_items',
                args: [],
                result: '0',
            ),
        ]),
    ]);

    $contents = collect($mapped)
        ->flatMap(fn (array $message): array => $message['content'])
        ->pluck('content')
        ->all();

    expect($contents)->toContain('0');
});

it('keeps a system prompt that is exactly "0" (anthropic)', function (): void {
    $mapped = AnthropicMessageMap::mapSystemMessages([new SystemMessage('0')]);

    expect(collect($mapped)->pluck('text')->all())->toContain('0');
});
