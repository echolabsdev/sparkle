<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Document;
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
        'content' => [
            ['type' => 'input_text', 'text' => 'Who are you?'],
        ],
    ]]);
});

it('maps user messages with additional attributes', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', additionalAttributes: ['name' => 'TJ']),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'role' => 'user',
        'content' => [
            ['type' => 'input_text', 'text' => 'Who are you?'],
        ],
        'name' => 'TJ',
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
        ->toBe('input_image');
    expect(data_get($mappedMessage, '0.content.1.image_url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url'))
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
        ->toBe('input_image');
    expect(data_get($mappedMessage, '0.content.1.image_url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url'))
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
        ->toBe('input_image');
    expect(data_get($mappedMessage, '0.content.1.image_url'))
        ->toBe('https://prismphp.com/storage/diamond.png');
});

it('maps user messages with images from file id', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromFileId('file_1234'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('input_image');
    expect(data_get($mappedMessage, '0.content.1.file_id'))
        ->toBe('file_1234');
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
        'content' => [
            [
                'type' => 'output_text',
                'text' => 'I am Nyx',
            ],
        ],
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
                    ],
                    'call_1234'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([
        [
            'id' => 'tool_1234',
            'call_id' => 'call_1234',
            'type' => 'function_call',
            'name' => 'search',
            'arguments' => json_encode([
                'query' => 'Laravel collection methods',
            ]),
        ],
        [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => 'I am Nyx',
                ],
            ],
        ],
    ]);
});

it('maps assistant message with tool calls with empty arguments as json object', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('', [
                new ToolCall(
                    'tool_1234',
                    'get_schema',
                    [],
                    'call_1234'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([
        [
            'id' => 'tool_1234',
            'call_id' => 'call_1234',
            'type' => 'function_call',
            'name' => 'get_schema',
            'arguments' => '{}',
        ],
    ]);
});

it('maps assistant message with multiple tool calls and different reasoning ids', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx', [
                new ToolCall(
                    'tool_1',
                    'search',
                    ['query' => 'Laravel collection methods'],
                    'call_1',
                    'rs_1',
                    [],
                ),
                new ToolCall(
                    'tool_2',
                    'search',
                    ['query' => 'Laravel query builder'],
                    'call_2',
                    'rs_2',
                    [],
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([
        [
            'type' => 'reasoning',
            'id' => 'rs_1',
            'summary' => [],
        ],
        [
            'id' => 'tool_1',
            'call_id' => 'call_1',
            'type' => 'function_call',
            'name' => 'search',
            'arguments' => json_encode(['query' => 'Laravel collection methods']),
        ],
        [
            'type' => 'reasoning',
            'id' => 'rs_2',
            'summary' => [],
        ],
        [
            'id' => 'tool_2',
            'call_id' => 'call_2',
            'type' => 'function_call',
            'name' => 'search',
            'arguments' => json_encode(['query' => 'Laravel query builder']),
        ],
        [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => 'I am Nyx',
                ],
            ],
        ],
    ]);
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
                    '[search results]',
                    'call_1234'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([[
        'type' => 'function_call_output',
        'call_id' => 'call_1234',
        'output' => '[search results]',
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
                ['type' => 'input_text', 'text' => 'Who are you?'],
            ],
        ],
    ]);
});

describe('documents', function (): void {
    it('maps user messages with pdf documents', function (): void {
        $messageMap = new MessageMap(
            messages: [
                new UserMessage('Here is the document', [
                    Document::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')), 'application/pdf'),
                ]),
            ],
            systemPrompts: []
        );

        $mappedMessage = $messageMap();

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('input_file')
            ->and(data_get($mappedMessage, '0.content.1.file_data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
    });

    it('maps user messages with file urls', function (): void {
        $messageMap = new MessageMap(
            messages: [
                new UserMessage('Here is the document', [
                    Document::fromUrl('https://example.com/test-pdf.pdf'),
                ]),
            ],
            systemPrompts: []
        );

        $mappedMessage = $messageMap();

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('input_file')
            ->and(data_get($mappedMessage, '0.content.1.file_url'))
            ->toBe('https://example.com/test-pdf.pdf');
    });

    it('maps previously uploaded files', function (): void {
        $messageMap = new MessageMap(
            messages: [
                new UserMessage('Here is the document', [
                    Document::fromFileId('previously-uploaded-file-id'),
                ]),
            ],
            systemPrompts: []
        );

        $mappedMessage = $messageMap();

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('input_file')
            ->and(data_get($mappedMessage, '0.content.1.file_id'))
            ->toBe('previously-uploaded-file-id');
    });
});
