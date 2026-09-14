<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
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

    expect($messageMap())->toBe([
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => 'Who are you?'],
            ],
        ]],
    ]);
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

    expect(data_get($mappedMessage, 'contents.0.parts'))->toHaveCount(2);

    expect(data_get($mappedMessage, 'contents.0.parts.1.inline_data.mime_type'))
        ->toBe('image/png');
    expect(data_get($mappedMessage, 'contents.0.parts.1.inline_data.data'))
        ->toBe(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
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

    expect(data_get($mappedMessage, 'contents.0.parts'))->toHaveCount(2);

    expect(data_get($mappedMessage, 'contents.0.parts.1.inline_data.mime_type'))
        ->toBe('image/png');
    expect(data_get($mappedMessage, 'contents.0.parts.1.inline_data.data'))
        ->toBe(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
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

        expect(data_get($mappedMessage, 'contents.0.parts'))->toHaveCount(2);

        expect(data_get($mappedMessage, 'contents.0.parts.1.text'))
            ->toBe('Here is the document');

        expect(data_get($mappedMessage, 'contents.0.parts.0.inline_data.mime_type'))
            ->toBe('application/pdf');

        expect(data_get($mappedMessage, 'contents.0.parts.0.inline_data.data'))
            ->toBe(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
    });

    it('maps user messages with text documents', function (): void {
        $messageMap = new MessageMap(
            messages: [
                new UserMessage('Here is the document', [
                    Document::fromLocalPath('tests/Fixtures/test-text.txt'),
                ]),
            ],
            systemPrompts: []
        );

        $mappedMessage = $messageMap();

        expect(data_get($mappedMessage, 'contents.0.parts'))->toHaveCount(2);

        expect(data_get($mappedMessage, 'contents.0.parts.1.text'))
            ->toBe('Here is the document');

        expect(data_get($mappedMessage, 'contents.0.parts.0.inline_data.mime_type'))
            ->toBe('text/plain');

        expect(data_get($mappedMessage, 'contents.0.parts.0.inline_data.data'))
            ->toBe(base64_encode(file_get_contents('tests/Fixtures/test-text.txt')));
    });
});

it('maps assistant message', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx'),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([
        'contents' => [[
            'role' => 'model',
            'parts' => [
                ['text' => 'I am Nyx'],
            ],
        ]],
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
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toEqual([
        'contents' => [[
            'role' => 'model',
            'parts' => [
                ['text' => 'I am Nyx'],
                [
                    'functionCall' => [
                        'name' => 'search',
                        'args' => (object) [
                            'query' => 'Laravel collection methods',
                        ],
                    ],
                ],
            ],
        ]],
    ]);
});

it('maps assistant message with tool calls with reasoning id', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx', [
                new ToolCall(
                    'tool_1234',
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ],
                    reasoningId: 'reasoning_1234'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toEqual([
        'contents' => [[
            'role' => 'model',
            'parts' => [
                ['text' => 'I am Nyx'],
                [
                    'functionCall' => [
                        'name' => 'search',
                        'args' => (object) [
                            'query' => 'Laravel collection methods',
                        ],
                    ],
                    'thoughtSignature' => 'reasoning_1234',
                ],
            ],
        ]],
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
                    '[search results]'
                ),
            ]),
        ],
        systemPrompts: []
    );

    expect($messageMap())->toBe([
        'contents' => [[
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => 'search',
                        'response' => [
                            'name' => 'search',
                            'content' => '"[search results]"',
                        ],
                    ],
                ],
            ],
        ]],
    ]);
});

it('maps system prompt', function (): void {
    $messageMap = new MessageMap(
        messages: [],
        systemPrompts: [
            new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]'),
        ]
    );

    expect($messageMap())->toBe([
        'system_instruction' => [
            'parts' => [
                ['text' => 'MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]'],
            ],
        ],
    ]);
});

it('throws an exception of multiple system prompts are given', function (): void {
    $messageMap = new MessageMap(
        messages: [],
        systemPrompts: [
            new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]'),
            new SystemMessage('But my friends call my Nyx.'),
        ]
    );

    $messageMap();
})->throws(PrismException::class, 'Gemini only supports one system instruction.');
