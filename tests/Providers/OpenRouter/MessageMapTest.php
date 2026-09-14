<?php

declare(strict_types=1);

use Prism\Prism\Providers\OpenRouter\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
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
        'content' => [
            ['type' => 'text', 'text' => 'Who are you?'],
        ],
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

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
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

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
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

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toBe('https://prismphp.com/storage/diamond.png');
});

it('maps user messages with audio input', function (): void {
    $audio = Audio::fromBase64(base64_encode('audio-content'), 'audio/wav');

    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [$audio]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))->toBe('input_audio');
    expect(data_get($mappedMessage, '0.content.1.input_audio.format'))->toBe('wav');
    expect(data_get($mappedMessage, '0.content.1.input_audio.data'))->toBe(base64_encode('audio-content'));
});

it('maps user messages with video input', function (): void {
    $video = Video::fromBase64(base64_encode('video-content'), 'video/mp4');

    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [$video]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))->toBe('video_url');
    expect(data_get($mappedMessage, '0.content.1.video_url.url'))->toBe('data:video/mp4;base64,'.base64_encode('video-content'));
});

it('maps assistant message', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx'),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toContain([
        'role' => 'assistant',
        'content' => 'I am Nyx',
    ]);
});

it('maps assistant message with tool calls', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx', [
                new ToolCall(
                    'tool_1234',
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ]
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'content' => 'I am Nyx',
        'tool_calls' => [[
            'id' => 'tool_1234',
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'arguments' => json_encode([
                    'query' => 'Laravel collection methods',
                ]),
            ],
        ]],
    ]]);
});

it('maps assistant message with tool calls with empty arguments as json object', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('', [
                new ToolCall(
                    'tool_1234',
                    'get_schema',
                    []
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'tool_calls' => [[
            'id' => 'tool_1234',
            'type' => 'function',
            'function' => [
                'name' => 'get_schema',
                'arguments' => '{}',
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
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ],
                    '[search results]'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'tool',
        'tool_call_id' => 'tool_1234',
        'content' => '[search results]',
    ]]);
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
            'content' => [
                ['type' => 'text', 'text' => 'Who are you?'],
            ],
        ],
    ]);
});

it('maps system prompt with cache_control', function (): void {
    $messageMap = new MessageMap(
        messages: [new UserMessage('Who are you?')],
        systemPrompts: [
            (new SystemMessage('I am a long re-usable system message.'))
                ->withProviderOptions(['cacheType' => 'ephemeral']),
        ]
    );

    expect($messageMap())->toBe([
        [
            'role' => 'system',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'I am a long re-usable system message.',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ],
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Who are you?'],
            ],
        ],
    ]);
});

it('maps user message with cache_control', function (): void {
    $messageMap = new MessageMap(
        messages: [
            (new UserMessage('I am a long re-usable user message.'))
                ->withProviderOptions(['cacheType' => 'ephemeral']),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'user',
        'content' => [
            [
                'type' => 'text',
                'text' => 'I am a long re-usable user message.',
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
    ]]);
});

it('maps user messages with documents from base64', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Here is the document', [
                Document::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')), 'application/pdf', 'test.pdf'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('file');
    expect(data_get($mappedMessage, '0.content.1.file.filename'))
        ->toBe('test.pdf');
    expect(data_get($mappedMessage, '0.content.1.file.file_data'))
        ->toStartWith('data:application/pdf;base64,');
    expect(data_get($mappedMessage, '0.content.1.file.file_data'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
});

it('maps assistant message with reasoning', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('The answer is 4.', [], [
                'reasoning' => 'Let me think step by step. 2 + 2 = 4.',
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'content' => 'The answer is 4.',
        'reasoning' => 'Let me think step by step. 2 + 2 = 4.',
    ]]);
});

it('maps assistant message with reasoning_details', function (): void {
    $reasoningDetails = [
        [
            'type' => 'reasoning.encrypted',
            'data' => 'gAAAAABo71-Jjh1ipTHmxLg2Jub6BwOV',
            'id' => 'rs_045e88aab38b',
            'format' => 'openai-responses-v1',
            'index' => 0,
        ],
    ];

    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('The answer is 4.', [], [
                'reasoning_details' => $reasoningDetails,
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'content' => 'The answer is 4.',
        'reasoning_details' => $reasoningDetails,
    ]]);
});

it('maps assistant message without reasoning when additionalContent is empty', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('Hello'),
        ],
        systemPrompts: []
    );

    $mapped = $messageMap();

    expect($mapped[0])->not->toHaveKey('reasoning');
    expect($mapped[0])->not->toHaveKey('reasoning_details');
    expect($mapped[0])->toBe([
        'role' => 'assistant',
        'content' => 'Hello',
    ]);
});

it('maps user messages with documents from url', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Here is the document', [
                Document::fromUrl('https://example.com/document.pdf', 'document.pdf'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('file');
    expect(data_get($mappedMessage, '0.content.1.file.filename'))
        ->toBe('document.pdf');
    expect(data_get($mappedMessage, '0.content.1.file.file_data'))
        ->toBe('https://example.com/document.pdf');
});
