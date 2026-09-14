<?php

declare(strict_types=1);

namespace Tests\Regression;

use Prism\Prism\Concerns\HandlesStructuredJson;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap as AnthropicMessageMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap as GeminiMessageMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap as OpenAIMessageMap;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * "0" is falsy in PHP, so `if ($content)` silently discards a message, delta or
 * payload whose entire text is the single character 0. Rector's
 * ExplicitBoolCompareRector made that explicit as `$content !== '' && $content
 * !== '0'` and spread it across ~30 call sites, which is how it survived.
 *
 * A lone "0" is ordinary model output — a count, a numeric answer, a JSON
 * number, one digit landing alone in a stream chunk. These assert it is carried
 * through rather than dropped.
 */
it('keeps a user message whose text is exactly "0" (gemini)', function (): void {
    $map = new GeminiMessageMap(
        messages: [new UserMessage('0')],
        systemPrompts: []
    );

    $parts = data_get($map(), 'contents.0.parts');

    // Note: no ->filter() here — Collection::filter() with no callback is the
    // very falsy-'0' trap this test exists to guard against.
    expect($parts)->toBeArray()
        ->and(collect($parts)->pluck('text')->all())
        ->toContain('0');
});

it('keeps an assistant message whose content is exactly "0" (openai)', function (): void {
    $map = new OpenAIMessageMap(
        messages: [new AssistantMessage('0')],
        systemPrompts: []
    );

    expect($map())->toContain([
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'output_text',
                'text' => '0',
            ],
        ],
    ]);
});

it('keeps an assistant message whose content is exactly "0" (anthropic)', function (): void {
    $mapped = AnthropicMessageMap::map([new AssistantMessage('0')]);

    $texts = collect($mapped)
        ->where('role', 'assistant')
        ->flatMap(fn (array $message): array => $message['content'])
        ->pluck('text')
        ->all();

    expect($texts)->toContain('0');
});

it('does not treat a file containing only "0" as empty', function (): void {
    // tempnam() creates the file it names, so keep that exact path rather than
    // appending an extension and leaking the original on every run.
    $path = tempnam(sys_get_temp_dir(), 'prism-zero');
    file_put_contents($path, '0');

    try {
        expect(Image::fromLocalPath($path, 'image/png')->base64())->toBe(base64_encode('0'));
    } finally {
        @unlink($path);
    }
});

describe('extractStructuredData', function (): void {
    // Parenthesised on purpose: chaining a method straight off `new class {}`
    // without parentheses is PHP 8.4 syntax, and this package supports ^8.2.
    $extract = fn (string $text): array => (new class
    {
        use HandlesStructuredJson;

        /**
         * @return array<string, mixed>
         */
        public function call(string $text): array
        {
            return $this->extractStructuredData($text);
        }
    })->call($text);

    it('returns an object payload untouched', function () use ($extract): void {
        expect($extract('{"count":0}'))->toBe(['count' => 0]);
    });

    // Valid JSON that decodes to a scalar must yield [] rather than a TypeError
    // against the declared array return. Previously only "0" was guarded, so
    // "12" and '"text"' blew up.
    it('returns an empty array for scalar JSON', function () use ($extract): void {
        expect($extract('0'))->toBe([])
            ->and($extract('12'))->toBe([])
            ->and($extract('"text"'))->toBe([])
            ->and($extract('true'))->toBe([]);
    });

    it('returns an empty array for empty or malformed input', function () use ($extract): void {
        expect($extract(''))->toBe([])
            ->and($extract('{not json'))->toBe([]);
    });
});
