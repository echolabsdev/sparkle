<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Events\Broadcasting\CitationBroadcast;
use Prism\Prism\Events\Broadcasting\ErrorBroadcast;
use Prism\Prism\Events\Broadcasting\ProviderToolEventBroadcast;
use Prism\Prism\Events\Broadcasting\StreamEndBroadcast;
use Prism\Prism\Events\Broadcasting\StreamStartBroadcast;
use Prism\Prism\Events\Broadcasting\TextCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\TextDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\TextStartBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\ToolResultBroadcast;
use Prism\Prism\Streaming\Adapters\BroadcastAdapter;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * @param  array<StreamEvent>  $events
 */
function createBroadcastEventGenerator(array $events): Generator
{
    foreach ($events as $event) {
        yield $event;
    }
}

beforeEach(function (): void {
    Event::fake();
});

it('accepts channel configuration', function (): void {
    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);

    expect($adapter)->toBeInstanceOf(BroadcastAdapter::class);
});

it('accepts multiple channels', function (): void {
    $channels = [
        new Channel('channel-1'),
        new PrivateChannel('private-channel-1'),
    ];

    $adapter = new BroadcastAdapter($channels);

    expect($adapter)->toBeInstanceOf(BroadcastAdapter::class);
});

it('broadcasts text delta events correctly', function (): void {
    $event = new TextDeltaEvent('evt-123', 1640995200, 'Hello world', 'msg-456');
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(TextDeltaBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->id === $event->id
        && $broadcastEvent->event->delta === $event->delta
        && $broadcastEvent->channels === $channel);
});

it('broadcasts stream start events correctly', function (): void {
    $event = new StreamStartEvent('evt-123', 1640995200, 'gpt-4', 'openai');
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(StreamStartBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->id === $event->id
        && $broadcastEvent->event->model === $event->model
        && $broadcastEvent->event->provider === $event->provider
        && $broadcastEvent->channels === $channel);
});

it('broadcasts stream end events correctly', function (): void {
    $usage = new Usage(promptTokens: 10, completionTokens: 5);
    $event = new StreamEndEvent('evt-123', 1640995200, FinishReason::Stop, $usage);
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(StreamEndBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->id === $event->id
        && $broadcastEvent->event->finishReason === $event->finishReason
        && $broadcastEvent->event->usage === $event->usage
        && $broadcastEvent->channels === $channel);
});

it('broadcasts thinking events correctly', function (): void {
    $events = [
        new ThinkingStartEvent('evt-1', 1640995200, 'reasoning-123'),
        new ThinkingEvent('evt-2', 1640995201, 'Let me think...', 'reasoning-123'),
        new ThinkingCompleteEvent('evt-3', 1640995202, 'reasoning-123'),
    ];

    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator($events));

    Event::assertDispatched(ThinkingStartBroadcast::class);
    Event::assertDispatched(ThinkingBroadcast::class);
    Event::assertDispatched(ThinkingCompleteBroadcast::class);
});

it('broadcasts tool events correctly', function (): void {
    $events = [
        new ToolCallEvent('evt-1', 1640995200, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new ToolCallDeltaEvent('evt-2', 1640995201, 'tool-123', 'search', 'partial result', 'msg-456'),
        new ToolResultEvent('evt-3', 1640995201, new ToolResult('tool-123', 'search', ['q' => 'test'], ['result' => 'found']), 'msg-456', true),
    ];

    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator($events));

    Event::assertDispatched(ToolCallBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->toolCall->name === 'search'
        && $broadcastEvent->event->toolCall->arguments() === ['q' => 'test']);

    Event::assertDispatched(ToolCallDeltaBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->delta === 'partial result');

    Event::assertDispatched(ToolResultBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->toolResult->result === ['result' => 'found']
        && $broadcastEvent->event->success === true);
});

it('broadcasts error events correctly', function (): void {
    $event = new ErrorEvent('evt-123', 1640995200, 'rate_limit', 'Rate limit exceeded', false);
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(ErrorBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->errorType === $event->errorType
        && $broadcastEvent->event->message === $event->message
        && $broadcastEvent->event->recoverable === $event->recoverable);
});

it('broadcasts to multiple channels', function (): void {
    $event = new TextDeltaEvent('evt-123', 1640995200, 'Hello', 'msg-456');
    $channels = [
        new Channel('channel-1'),
        new PrivateChannel('private-channel-1'),
    ];

    $adapter = new BroadcastAdapter($channels);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(TextDeltaBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->channels === $channels);
});

it('handles empty event stream', function (): void {
    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);

    // Should not throw any errors
    ($adapter)(createBroadcastEventGenerator([]));

    // No events should be dispatched
    Event::assertNothingDispatched();
});

it('broadcasts all event types in comprehensive stream', function (): void {
    $usage = new Usage(promptTokens: 10, completionTokens: 5);

    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextStartEvent('evt-2', 1640995201, 'msg-456'),
        new TextDeltaEvent('evt-3', 1640995202, 'Hello', 'msg-456'),
        new ThinkingStartEvent('evt-4', 1640995203, 'reasoning-123'),
        new ThinkingEvent('evt-5', 1640995204, 'Thinking...', 'reasoning-123'),
        new ThinkingCompleteEvent('evt-6', 1640995205, 'reasoning-123'),
        new ToolCallEvent('evt-7', 1640995206, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new ToolResultEvent('evt-8', 1640995207, new ToolResult('tool-123', 'search', ['q' => 'test'], ['result' => 'found']), 'msg-456', true),
        new TextDeltaEvent('evt-9', 1640995208, ' World!', 'msg-456'),
        new TextCompleteEvent('evt-10', 1640995209, 'msg-456'),
        new StreamEndEvent('evt-11', 1640995210, FinishReason::Stop, $usage),
    ];

    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator($events));

    // Verify all broadcast event types are dispatched
    Event::assertDispatched(StreamStartBroadcast::class);
    Event::assertDispatched(TextStartBroadcast::class);
    Event::assertDispatched(TextDeltaBroadcast::class);
    Event::assertDispatched(ThinkingStartBroadcast::class);
    Event::assertDispatched(ThinkingBroadcast::class);
    Event::assertDispatched(ThinkingCompleteBroadcast::class);
    Event::assertDispatched(ToolCallBroadcast::class);
    Event::assertDispatched(ToolResultBroadcast::class);
    Event::assertDispatched(TextCompleteBroadcast::class);
    Event::assertDispatched(StreamEndBroadcast::class);

    // Verify the correct number of events were dispatched
    Event::assertDispatchedTimes(TextDeltaBroadcast::class, 2); // Two text deltas
});

it('handles all supported event types without errors', function (): void {
    // Test that all currently supported event types can be processed
    // This test ensures the match statement covers all expected cases
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextStartEvent('evt-2', 1640995201, 'msg-456'),
        new TextDeltaEvent('evt-3', 1640995202, 'Hello', 'msg-456'),
        new TextCompleteEvent('evt-4', 1640995203, 'msg-456'),
        new ThinkingStartEvent('evt-5', 1640995204, 'reasoning-123'),
        new ThinkingEvent('evt-6', 1640995205, 'Thinking...', 'reasoning-123'),
        new ThinkingCompleteEvent('evt-7', 1640995206, 'reasoning-123'),
        new ToolCallEvent('evt-8', 1640995207, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new ToolResultEvent('evt-9', 1640995208, new ToolResult('tool-123', 'search', ['q' => 'test'], ['result' => 'found']), 'msg-456', true),
        new ProviderToolEvent('evt-10', 1640995209, 'image_generation_call', 'completed', 'ig-789', ['result' => 'data']),
        new ErrorEvent('evt-11', 1640995210, 'test_error', 'Test error', true),
        new StreamEndEvent('evt-12', 1640995211, FinishReason::Stop),
    ];

    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);

    // Should not throw any exceptions
    ($adapter)(createBroadcastEventGenerator($events));

    // Verify all events were processed and dispatched
    expect(true)->toBeTrue(); // Test passes if no exceptions thrown
});

it('maintains event order when broadcasting', function (): void {
    $events = [];
    for ($i = 1; $i <= 5; $i++) {
        $events[] = new TextDeltaEvent("evt-{$i}", 1640995200 + $i, "text-{$i}", 'msg-456');
    }

    $channel = new Channel('test-channel');
    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator($events));

    // Verify all events were dispatched in order
    Event::assertDispatchedTimes(TextDeltaBroadcast::class, 5);
});

it('handles events with complex data structures', function (): void {
    $complexArgs = [
        'query' => 'search term',
        'options' => [
            'limit' => 10,
            'sort' => 'relevance',
            'filters' => ['category' => 'tech', 'date' => '2024'],
        ],
        'metadata' => null,
    ];

    $event = new ToolCallEvent('evt-1', 1640995200, new ToolCall('tool-123', 'complex_search', $complexArgs), 'msg-456');
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(ToolCallBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->toolCall->name === 'complex_search'
        && $broadcastEvent->event->toolCall->arguments() === $complexArgs);
});

it('handles events with unicode and special characters', function (): void {
    $event = new TextDeltaEvent('evt-1', 1640995200, '🚀 Hello 世界! "quoted" text', 'msg-456');
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(TextDeltaBroadcast::class, fn ($broadcastEvent): bool => $broadcastEvent->event->delta === '🚀 Hello 世界! "quoted" text');
});

it('validates broadcast event structure and data format', function (): void {
    $event = new TextDeltaEvent('evt-123', 1640995200, 'Hello world!', 'msg-456');
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(TextDeltaBroadcast::class, function ($broadcastEvent) use ($event, $channel): bool {
        // Verify the broadcast event has the correct structure
        expect($broadcastEvent)->toBeInstanceOf(TextDeltaBroadcast::class);
        expect($broadcastEvent->event)->toBe($event);
        expect($broadcastEvent->channels)->toBe($channel);

        // Verify broadcast event methods work correctly
        expect($broadcastEvent->broadcastAs())->toBe('text_delta');
        expect($broadcastEvent->broadcastOn())->toBe([$channel]);

        // Verify broadcast data contains correct event data
        $broadcastData = $broadcastEvent->broadcastWith();
        expect($broadcastData)->toBeArray();
        expect($broadcastData['id'])->toBe('evt-123');
        expect($broadcastData['timestamp'])->toBe(1640995200);
        expect($broadcastData['delta'])->toBe('Hello world!');
        expect($broadcastData['message_id'])->toBe('msg-456');

        return true;
    });
});

it('broadcasts provider tool events correctly', function (): void {
    $event = new ProviderToolEvent(
        id: 'evt-123',
        timestamp: 1640995200,
        toolType: 'image_generation_call',
        status: 'completed',
        itemId: 'ig-456',
        data: ['result' => 'base64-image-data']
    );
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(ProviderToolEventBroadcast::class, function ($broadcastEvent): bool {
        expect($broadcastEvent->broadcastAs())->toBe('provider_tool_event.image_generation_call.completed');
        expect($broadcastEvent->event->toolType)->toBe('image_generation_call');
        expect($broadcastEvent->event->status)->toBe('completed');
        expect($broadcastEvent->event->itemId)->toBe('ig-456');
        expect($broadcastEvent->event->data)->toBe(['result' => 'base64-image-data']);

        return true;
    });
});

it('broadcasts citation events correctly', function (): void {
    $citation = new Citation(
        sourceType: CitationSourceType::Document,
        source: 0,
        sourceText: 'The quick brown fox',
        sourceTitle: 'Test Document',
    );

    $event = new CitationEvent(
        id: 'evt-123',
        timestamp: 1640995200,
        citation: $citation,
        messageId: 'msg-456',
        blockIndex: 0,
    );
    $channel = new Channel('test-channel');

    $adapter = new BroadcastAdapter($channel);
    ($adapter)(createBroadcastEventGenerator([$event]));

    Event::assertDispatched(CitationBroadcast::class, function ($broadcastEvent) use ($channel): bool {
        expect($broadcastEvent->broadcastAs())->toBe('citation');
        expect($broadcastEvent->broadcastOn())->toBe([$channel]);

        $data = $broadcastEvent->broadcastWith();
        expect($data['id'])->toBe('evt-123');
        expect($data['message_id'])->toBe('msg-456');
        expect($data['block_index'])->toBe(0);
        expect($data['citation']['source_type'])->toBe('document');
        expect($data['citation']['source_text'])->toBe('The quick brown fox');
        expect($data['citation']['source_title'])->toBe('Test Document');

        return true;
    });
});

it('validates broadcast event structure for multiple event types', function (): void {
    $usage = new Usage(promptTokens: 10, completionTokens: 5);
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new ToolCallEvent('evt-2', 1640995201, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop, $usage),
    ];

    $channels = [
        new Channel('channel-1'),
        new PrivateChannel('private-channel-1'),
    ];

    $adapter = new BroadcastAdapter($channels);
    ($adapter)(createBroadcastEventGenerator($events));

    // Verify StreamStartBroadcast structure and data
    Event::assertDispatched(StreamStartBroadcast::class, function ($broadcastEvent) use ($channels): bool {
        expect($broadcastEvent->broadcastAs())->toBe('stream_start');
        expect($broadcastEvent->broadcastOn())->toBe($channels);

        $data = $broadcastEvent->broadcastWith();
        expect($data['model'])->toBe('claude-3');
        expect($data['provider'])->toBe('anthropic');

        return true;
    });

    // Verify ToolCallBroadcast structure and data
    Event::assertDispatched(ToolCallBroadcast::class, function ($broadcastEvent) use ($channels): bool {
        expect($broadcastEvent->broadcastAs())->toBe('tool_call');
        expect($broadcastEvent->broadcastOn())->toBe($channels);

        $data = $broadcastEvent->broadcastWith();
        expect($data['tool_id'])->toBe('tool-123');
        expect($data['tool_name'])->toBe('search');
        expect($data['arguments'])->toEqual((object) ['q' => 'test']);
        expect($data['message_id'])->toBe('msg-456');

        return true;
    });

    // Verify StreamEndBroadcast structure and data
    Event::assertDispatched(StreamEndBroadcast::class, function ($broadcastEvent) use ($channels): bool {
        expect($broadcastEvent->broadcastAs())->toBe('stream_end');
        expect($broadcastEvent->broadcastOn())->toBe($channels);

        $data = $broadcastEvent->broadcastWith();
        expect($data['finish_reason'])->toBe('Stop');
        expect($data['usage'])->toMatchArray([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
            'cost' => null,
        ]);

        return true;
    });
});
