<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps system messages correctly', function (): void {
    $systemMessage = new SystemMessage('System instruction');

    $messageMap = new MessageMap([$systemMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'system',
            'content' => 'System instruction',
        ],
    ]);
});

it('maps user messages correctly', function (): void {
    $userMessage = new UserMessage('User input');

    $messageMap = new MessageMap([$userMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'user',
            'content' => 'User input',
        ],
    ]);
});

it('maps user messages with images correctly', function (): void {
    $image = Image::fromLocalPath('tests/Fixtures/diamond.png');
    $userMessage = new UserMessage('User input with image', [$image]);

    $messageMap = new MessageMap([$userMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'user',
            'content' => 'User input with image',
            'images' => [base64_encode(file_get_contents('tests/Fixtures/diamond.png'))],
        ],
    ]);
});

it('refuses a url image without fetching it, and says what to call instead', function (): void {
    // UNTESTED BEFORE G-44, which is why this path would have failed silently.
    // Ollama takes image bytes only, and its mapper used to accept a URL because
    // reading the bytes fetched it — through an unguarded server-side request.
    // With that fetch gone, accepting the URL would pass validation and send
    // `null` as the image, and no existing test would have noticed.
    Http::fake(['*' => Http::response(file_get_contents('tests/Fixtures/diamond.png'))]);

    $userMessage = new UserMessage('User input with image', [Image::fromUrl('http://169.254.169.254/latest/meta-data/')]);

    expect(fn (): array => (new MessageMap([$userMessage]))->map())
        ->toThrow(PrismException::class, 'fetchUrlContent()');

    Http::assertSentCount(0);
});

it('still sends a url image once it has been fetched explicitly', function (): void {
    // The positive control, and the migration: a caller who trusts the URL gets
    // exactly what they used to get, by writing the fetch.
    Http::fake(['*' => Http::response(file_get_contents('tests/Fixtures/diamond.png'))]);

    $image = Image::fromUrl('https://prismphp.com/storage/diamond.png')->fetchUrlContent();
    $result = (new MessageMap([new UserMessage('User input with image', [$image])]))->map();

    Http::assertSentCount(1);
    expect($result[0]['images'])->toBe([base64_encode(file_get_contents('tests/Fixtures/diamond.png'))]);
});

it('maps assistant messages correctly', function (): void {
    $assistantMessage = new AssistantMessage('Assistant response');

    $messageMap = new MessageMap([$assistantMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
        ],
    ]);
});

it('maps assistant messages with tool calls correctly', function (): void {
    $assistantMessage = new AssistantMessage('Assistant response', [
        new ToolCall(
            id: '',
            name: 'search',
            arguments: [
                'query' => 'What is Prism?',
            ]
        ),
    ]);

    $messageMap = new MessageMap([$assistantMessage]);
    $result = $messageMap->map();

    expect($result)->toEqual([
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
            'tool_calls' => [[
                'function' => [
                    'name' => 'search',
                    'arguments' => (object) [
                        'query' => 'What is Prism?',
                    ],
                ],
            ]],
        ],
    ]);
});

it('maps tool result messages correctly', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-1',
        toolName: 'test-tool',
        args: ['query' => 'test'],
        result: 'Tool execution result'
    );
    $toolResultMessage = new ToolResultMessage([$toolResult]);

    $messageMap = new MessageMap([$toolResultMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'tool',
            'tool_name' => 'test-tool',
            'content' => 'Tool execution result',
        ],
    ]);
});

it('maps tool result messages with non-string results correctly', function (): void {
    $toolResult = new ToolResult(
        toolCallId: 'tool-1',
        toolName: 'test-tool',
        args: ['query' => 'test'],
        result: ['key' => 'value']
    );
    $toolResultMessage = new ToolResultMessage([$toolResult]);

    $messageMap = new MessageMap([$toolResultMessage]);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'tool',
            'tool_name' => 'test-tool',
            'content' => '{"key":"value"}',
        ],
    ]);
});

it('maps multiple messages in sequence correctly', function (): void {
    $messages = [
        new SystemMessage('System instruction'),
        new UserMessage('User input'),
        new AssistantMessage('Assistant response'),
    ];

    $messageMap = new MessageMap($messages);
    $result = $messageMap->map();

    expect($result)->toBe([
        [
            'role' => 'system',
            'content' => 'System instruction',
        ],
        [
            'role' => 'user',
            'content' => 'User input',
        ],
        [
            'role' => 'assistant',
            'content' => 'Assistant response',
        ],
    ]);
});

it('throws exception for unknown message type', function (): void {
    $invalidMessage = new class implements Message {};
    $messageMap = new MessageMap([$invalidMessage]);

    expect($messageMap->map(...))
        ->toThrow(Exception::class, 'Could not map message type '.$invalidMessage::class);
});
