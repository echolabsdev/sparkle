<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * A stored message is read back by something that is not PHP — another
 * language, a database's JSON operators, a browser. Those all distinguish `{}`
 * from `[]`, so a map-typed field that changes JSON type with its contents
 * changes the SHAPE of the record depending on whether anyone filled it in.
 */
it('serialises an empty additionalAttributes as an object', function (): void {
    $message = new UserMessage('hi');

    expect(json_encode($message->toArray()['additional_attributes']))->toBe('{}');
});

it('serialises a populated additionalAttributes as the same object it always was', function (): void {
    $message = new UserMessage('hi', additionalAttributes: ['tenant' => 'acme']);

    expect(json_encode($message->toArray()['additional_attributes']))->toBe('{"tenant":"acme"}');
});

it('serialises an empty assistant additionalContent as an object', function (): void {
    $message = new AssistantMessage('Done.');

    expect(json_encode($message->toArray()['additional_content']))->toBe('{}');
});

it('serialises empty tool result args as an object', function (): void {
    $result = new ToolResult(toolCallId: 'call-1', toolName: 'now', args: [], result: 'noon');

    expect(json_encode($result->toArray()['args']))->toBe('{}');
});

it('serialises empty provider tool call data as an object', function (): void {
    $call = new ProviderToolCall(id: 'ptc-1', type: 'web_search', status: 'completed', data: []);

    expect(json_encode($call->toArray()['data']))->toBe('{}');
});

it('leaves list-typed fields as lists', function (): void {
    // The rule is scoped to MAPS. A list is `[]` in every language, and
    // wrapping one would corrupt the payload rather than fix it.
    $message = new AssistantMessage('Done.');

    expect(json_encode($message->toArray()['tool_calls']))->toBe('[]')
        ->and(json_encode($message->toArray()['tool_approval_requests']))->toBe('[]');
});
