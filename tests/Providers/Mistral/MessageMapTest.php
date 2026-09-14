<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Mistral\Maps\MessageMap;
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
            ['type' => 'text', 'text' => 'Who are you?'],
        ],
    ]]);
});

it('maps user messages with images', function (): void {
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
        'content' => '[search results]',
        'tool_call_id' => 'tool_1234',
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

it('maps user messages with documents from a url', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Document::fromUrl('https://prismphp.com/storage/diamond.png')->setDocumentTitle('diamond'),
            ]),
        ],
        systemPrompts: []
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

    expect(data_get($mappedMessage, '0.content.1.type'))->toBe('document_url');

    expect(data_get($mappedMessage, '0.content.1.document_url'))->toBe('https://prismphp.com/storage/diamond.png');

    expect(data_get($mappedMessage, '0.content.1.document_name'))->toBe('diamond');
});

it('throws an exception for a non-url document', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Document::fromLocalPath('tests/Fixtures/diamond.png'),
            ]),
        ],
        systemPrompts: []
    );

    $messageMap();
})->throws(PrismException::class, "The mistral provider does not support the mediums available in the provided `Prism\Prism\Providers\Mistral\Maps\DocumentMapper`. Please consult the Prism documentation for more information on which mediums the mistral provider supports.");
