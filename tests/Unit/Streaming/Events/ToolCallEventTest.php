<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\ValueObjects\ToolCall;

it('constructs with required parameters', function (): void {
    $arguments = ['query' => 'hello world', 'max_results' => 10];
    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'search',
        arguments: $arguments
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->toolCall)->toBe($toolCall)
        ->and($event->toolCall->id)->toBe('tool-456')
        ->and($event->toolCall->name)->toBe('search')
        ->and($event->toolCall->arguments())->toBe($arguments)
        ->and($event->messageId)->toBe('msg-789')
        ->and($event->toolCall->reasoningId)->toBeNull();
});

it('constructs with reasoning id', function (): void {
    $arguments = ['file' => 'data.txt'];
    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'read_file',
        arguments: $arguments,
        reasoningId: 'reasoning-101'
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->toolCall->reasoningId)->toBe('reasoning-101');
});

it('returns correct stream event type', function (): void {
    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'calculator',
        arguments: []
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->type())->toBe(StreamEventType::ToolCall);
});

it('converts to array with all properties', function (): void {
    $arguments = [
        'expression' => '2 + 2',
        'precision' => 2,
        'format' => 'decimal',
    ];

    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'calculator',
        arguments: $arguments,
        reasoningId: 'reasoning-101'
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    $array = $event->toArray();

    expect($array)->toEqual([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'tool_name' => 'calculator',
        'arguments' => (object) $arguments,
        'message_id' => 'msg-789',
        'reasoning_id' => 'reasoning-101',
    ]);
});

it('converts to array with null reasoning id', function (): void {
    $arguments = ['prompt' => 'Generate a story'];
    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'text_generator',
        arguments: $arguments
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    $array = $event->toArray();

    expect($array)->toEqual([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_id' => 'tool-456',
        'tool_name' => 'text_generator',
        'arguments' => (object) $arguments,
        'message_id' => 'msg-789',
        'reasoning_id' => null,
    ]);
});

it('handles empty arguments array', function (): void {
    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'ping',
        arguments: []
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->toolCall->arguments())->toBe([])
        ->and($event->toArray()['arguments'])->toEqual(new stdClass);
});

it('handles complex nested arguments', function (): void {
    $arguments = [
        'config' => [
            'timeout' => 30,
            'retries' => 3,
            'options' => ['verbose', 'cache'],
        ],
        'data' => [
            'users' => [
                ['name' => 'John', 'age' => 30],
                ['name' => 'Jane', 'age' => 25],
            ],
        ],
        'metadata' => [
            'version' => '1.0.0',
            'timestamp' => 1640995200,
        ],
    ];

    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'complex_processor',
        arguments: $arguments
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->toolCall->arguments())->toBe($arguments)
        ->and($event->toArray()['arguments'])->toEqual((object) $arguments);
});

it('handles string and numeric arguments', function (): void {
    $arguments = [
        'string_param' => 'hello',
        'int_param' => 42,
        'float_param' => 3.14,
        'bool_param' => true,
        'null_param' => null,
    ];

    $toolCall = new ToolCall(
        id: 'tool-456',
        name: 'multi_type_tool',
        arguments: $arguments
    );

    $event = new ToolCallEvent(
        id: 'event-123',
        timestamp: 1640995200,
        toolCall: $toolCall,
        messageId: 'msg-789'
    );

    expect($event->toolCall->arguments())->toBe($arguments);
});

it('handles empty string properties', function (): void {
    $toolCall = new ToolCall(
        id: '',
        name: '',
        arguments: [],
        reasoningId: ''
    );

    $event = new ToolCallEvent(
        id: '',
        timestamp: 0,
        toolCall: $toolCall,
        messageId: ''
    );

    expect($event->id)->toBe('')
        ->and($event->toolCall->id)->toBe('')
        ->and($event->toolCall->name)->toBe('')
        ->and($event->messageId)->toBe('')
        ->and($event->toolCall->reasoningId)->toBe('');
});
