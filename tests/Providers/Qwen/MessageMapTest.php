<?php

declare(strict_types=1);

use Prism\Prism\Providers\Qwen\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps user messages', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?'),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'user',
        'content' => 'Who are you?',
    ]]);
});

it('maps user messages with images from path', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromLocalPath('tests/Fixtures/diamond.png'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    // DashScope native multimodal format: images + text in content array
    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    // Image comes first as {"image": "data:...;base64,..."}
    expect(data_get($mappedMessage, '0.content.0.image'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.0.image'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));

    // Text comes last as {"text": "..."}
    expect(data_get($mappedMessage, '0.content.1.text'))
        ->toBe('Who are you?');
});

it('maps user messages with images from base64', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/diamond.png')), 'image/png'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.0.image'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.0.image'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));

    expect(data_get($mappedMessage, '0.content.1.text'))
        ->toBe('Who are you?');
});

it('maps user messages with images from url', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromUrl('https://prismphp.com/storage/diamond.png'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.0.image'))
        ->toBe('https://prismphp.com/storage/diamond.png');

    expect(data_get($mappedMessage, '0.content.1.text'))
        ->toBe('Who are you?');
});

it('maps assistant message', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Qwen'),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toContain([
        'role' => 'assistant',
        'content' => 'I am Qwen',
    ]);
});

it('maps assistant message with tool calls', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Qwen', [
                new ToolCall(
                    'tool_1234',
                    'weather',
                    [
                        'city' => 'Detroit',
                    ]
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'content' => 'I am Qwen',
        'tool_calls' => [[
            'id' => 'tool_1234',
            'type' => 'function',
            'function' => [
                'name' => 'weather',
                'arguments' => json_encode([
                    'city' => 'Detroit',
                ]),
            ],
        ]],
    ]]);
});

it('maps tool result messages', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new ToolResultMessage([
                new ToolResult(
                    'tool_1234',
                    'weather',
                    [
                        'city' => 'Detroit',
                    ],
                    '[weather results]'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'tool',
        'tool_call_id' => 'tool_1234',
        'content' => '[weather results]',
    ]]);
});

it('maps user messages with multiple images', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('这些是什么?', [
                Image::fromUrl('https://example.com/dog.jpeg'),
                Image::fromUrl('https://example.com/tiger.png'),
                Image::fromUrl('https://example.com/rabbit.png'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    // 3 images + 1 text = 4 content items
    expect(data_get($mappedMessage, '0.content'))->toHaveCount(4);

    expect(data_get($mappedMessage, '0.content.0.image'))->toBe('https://example.com/dog.jpeg');
    expect(data_get($mappedMessage, '0.content.1.image'))->toBe('https://example.com/tiger.png');
    expect(data_get($mappedMessage, '0.content.2.image'))->toBe('https://example.com/rabbit.png');
    expect(data_get($mappedMessage, '0.content.3.text'))->toBe('这些是什么?');
});

it('detects images in messages via hasImages', function (): void {
    $withImages = new MessageMap(
        messages: [
            new UserMessage('Describe this', [
                Image::fromUrl('https://example.com/photo.png'),
            ]),
        ],
        systemPrompts: []
    );

    $withoutImages = new MessageMap(
        messages: [
            new UserMessage('Hello'),
        ],
        systemPrompts: []
    );

    expect($withImages->hasImages())->toBeTrue();
    expect($withoutImages->hasImages())->toBeFalse();
});

it('maps system prompt', function (): void {
    $messageMap = new MessageMap(
        messages: [new UserMessage('Who are you?')],
        systemPrompts: [
            new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]'),
            new SystemMessage('But my friends call me Nyx'),
        ]
    );

    expect($messageMap())->toBe([
        [
            'role' => 'system',
            'content' => 'MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]',
        ],
        [
            'role' => 'system',
            'content' => 'But my friends call me Nyx',
        ],
        [
            'role' => 'user',
            'content' => 'Who are you?',
        ],
    ]);
});
