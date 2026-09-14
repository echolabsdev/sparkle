<?php

declare(strict_types=1);

namespace Tests\Providers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\FixtureResponse;

/**
 * These assert on the BYTES, and they assert on them as TEXT.
 *
 * `$request->data()` hands back the array that was about to be encoded, which
 * is not the thing under test — and decoding `$request->body()` in PHP would
 * destroy the evidence with the very defect being looked for, since `{}` and
 * `[]` decode identically here. So the payload is matched as a string.
 *
 * The unit tests around ToolCall prove the value object; these prove that what
 * it produces survives the message map, the request builder and the transport.
 */
function sentBody(): string
{
    $body = '';

    Http::assertSent(function (Request $request) use (&$body): bool {
        $body = $request->body();

        return true;
    });

    return $body;
}

it('sends an empty tool call argument set as an object, through the transport', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            new UserMessage('What time is it?'),
            new AssistantMessage('', [new ToolCall('call_1', 'now', [])]),
            new ToolResultMessage([new ToolResult('call_1', 'now', [], 'noon')]),
        ])
        ->asText();

    expect(sentBody())->toContain('"arguments":"{}"')
        ->and(sentBody())->not->toContain('"arguments":"[]"');
});

it('keeps a nested empty object nested and empty, rather than turning it into a list', function (): void {
    // The case a key-aware rule cannot reach: the map is not a field Prism
    // declares, it is arbitrary JSON the MODEL produced, nested three deep.
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            new UserMessage('Search'),
            new AssistantMessage('', [
                new ToolCall('call_1', 'search', '{"filter":{},"tags":[],"deep":{"a":{"b":{}}}}'),
            ]),
            new ToolResultMessage([new ToolResult('call_1', 'search', [], 'ok')]),
        ])
        ->asText();

    expect(sentBody())->toContain('{\"filter\":{},\"tags\":[],\"deep\":{\"a\":{\"b\":{}}}}');
});

it('sends an empty tool call input as an object to Anthropic, which nests it rather than encoding it', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([
            new UserMessage('What time is it?'),
            new AssistantMessage('', [new ToolCall('call_1', 'now', [])]),
            new ToolResultMessage([new ToolResult('call_1', 'now', [], 'noon')]),
        ])
        ->asText();

    expect(sentBody())->toContain('"input":{}')
        ->and(sentBody())->not->toContain('"input":[]');
});

it('sends a parameterless tool schema with an object properties, through the transport', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What time is it?')
        ->withTools([
            Tool::as('now')->for('Returns the current time')->using(fn (): string => 'noon'),
            Tool::as('search')->for('Searches')
                ->withStringParameter('query', 'the query')
                ->using(fn (): string => '[]'),
        ])
        ->asText();

    expect(sentBody())->not->toContain('"properties":[]');
});
