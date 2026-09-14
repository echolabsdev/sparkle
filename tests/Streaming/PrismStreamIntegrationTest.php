<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('asStream returns generator of stream events with simple text response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test prompt')
        ->asStream();

    expect($events)->toBeInstanceOf(Generator::class);

    $eventArray = iterator_to_array($events);

    expect(count($eventArray))->toBeGreaterThanOrEqual(7); // StreamStart, StepStart, TextStart, TextDelta(s), TextComplete, StepFinish, StreamEnd
    expect($eventArray[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($eventArray[1])->toBeInstanceOf(StepStartEvent::class);
    expect($eventArray[2])->toBeInstanceOf(TextStartEvent::class);

    $lastIndex = count($eventArray) - 1;
    expect($eventArray[$lastIndex])->toBeInstanceOf(StreamEndEvent::class);
    expect($eventArray[$lastIndex - 1])->toBeInstanceOf(StepFinishEvent::class);
    expect($eventArray[$lastIndex - 2])->toBeInstanceOf(TextCompleteEvent::class);
});

it('asStream yields text delta events with chunked content', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $textDeltas = [];
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $textDeltas[] = $event->delta;
        }
    }

    $reconstructedText = implode('', $textDeltas);
    expect($reconstructedText)->toBe('Hello World');
    expect(count($textDeltas))->toBeGreaterThan(1);
});

it('asStream includes stream start event with model and provider info', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test'),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-opus')
        ->withPrompt('Test')
        ->asStream();

    $startEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamStartEvent) {
            $startEvent = $event;

            break;
        }
    }

    expect($startEvent)->not->toBeNull();
    expect($startEvent->model)->toBe('claude-3-opus');
    expect($startEvent->provider)->toBe('fake');
});

it('asStream includes stream end event with finish reason and usage', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Test response')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(100, 50)),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $endEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->not->toBeNull();
    expect($endEvent->finishReason)->toBe(FinishReason::Stop);
    expect($endEvent->usage)->not->toBeNull();
    expect($endEvent->usage->promptTokens)->toBe(100);
    expect($endEvent->usage->completionTokens)->toBe(50);
});

it('asStream handles responses with tool calls', function (): void {
    $toolCall = new ToolCall('tool-123', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Let me search for that')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Search for something')
        ->asStream();

    $toolCallEvents = [];
    foreach ($events as $event) {
        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
        }
    }

    expect($toolCallEvents)->toHaveCount(1);
    expect($toolCallEvents[0]->toolCall->name)->toBe('search');
    expect($toolCallEvents[0]->toolCall->arguments())->toBe(['query' => 'test']);
});

it('asStream handles responses with tool results', function (): void {
    $toolResult = new ToolResult('tool-123', 'search', ['query' => 'test'], ['result' => 'found']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withToolResults([$toolResult]),
        ])),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $toolResultEvents = [];
    foreach ($events as $event) {
        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }
    }

    expect($toolResultEvents)->toHaveCount(1);
    expect($toolResultEvents[0]->toolResult->result)->toBe(['result' => 'found']);
    expect($toolResultEvents[0]->success)->toBeTrue();
});

it('asStream handles empty text response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText(''),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $eventArray = iterator_to_array($events);

    // StreamStart, StepStart, StepFinish, StreamEnd (no text events for empty response)
    expect($eventArray)->toHaveCount(4);
    expect($eventArray[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($eventArray[1])->toBeInstanceOf(StepStartEvent::class);
    expect($eventArray[2])->toBeInstanceOf(StepFinishEvent::class);
    expect($eventArray[3])->toBeInstanceOf(StreamEndEvent::class);
});

it('asStream handles multi-step responses with text and tool calls', function (): void {
    $toolCall = new ToolCall('tool-1', 'calculator', ['operation' => 'add', 'a' => 1, 'b' => 2]);
    $toolResult = new ToolResult('tool-1', 'calculator', ['operation' => 'add', 'a' => 1, 'b' => 2], ['result' => 3]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Let me calculate that')
                ->withToolCalls([$toolCall]),
            TextStepFake::make()
                ->withToolResults([$toolResult]),
            TextStepFake::make()
                ->withText('The result is 3'),
        ])),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is 1 + 2?')
        ->asStream();

    $eventTypes = [];
    foreach ($events as $event) {
        $eventTypes[] = $event::class;
    }

    expect($eventTypes)->toContain(StreamStartEvent::class);
    expect($eventTypes)->toContain(TextStartEvent::class);
    expect($eventTypes)->toContain(TextDeltaEvent::class);
    expect($eventTypes)->toContain(TextCompleteEvent::class);
    expect($eventTypes)->toContain(ToolCallEvent::class);
    expect($eventTypes)->toContain(ToolResultEvent::class);
    expect($eventTypes)->toContain(StreamEndEvent::class);
});

it('asStream maintains correct event sequence order', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $eventArray = iterator_to_array($events);

    expect($eventArray[0])->toBeInstanceOf(StreamStartEvent::class);
    expect(end($eventArray))->toBeInstanceOf(StreamEndEvent::class);
});

it('asEventStreamResponse returns streamed response with SSE headers', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello from SSE'),
    ]);

    $response = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test')
        ->asEventStreamResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
    expect($response->headers->get('Connection'))->toBe('keep-alive');
});

it('asEventStreamResponse callback outputs valid SSE format', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asEventStreamResponse();

    $callback = $response->getCallback();
    expect($callback)->toBeCallable();

    $outputBuffer = fopen('php://memory', 'r+');
    ob_start(function ($buffer) use ($outputBuffer): string {
        fwrite($outputBuffer, $buffer);

        return '';
    });

    try {
        $callback();
        $output = ob_get_clean();

        rewind($outputBuffer);
        $output = stream_get_contents($outputBuffer);

        expect($output)->toContain('event: stream_start');
        expect($output)->toContain('event: text_delta');
        expect($output)->toContain('event: stream_end');
        expect($output)->toContain('data: ');
    } finally {
        fclose($outputBuffer);
    }
});

it('asDataStreamResponse returns streamed response with correct headers', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello from Data Protocol'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asDataStreamResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('Cache-Control'))->toContain('no-transform');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('asDataStreamResponse callback outputs valid data protocol format', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test'),
    ]);

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asDataStreamResponse();

    $callback = $response->getCallback();
    expect($callback)->toBeCallable();

    $outputBuffer = fopen('php://memory', 'r+');
    ob_start(function ($buffer) use ($outputBuffer): string {
        fwrite($outputBuffer, $buffer);

        return '';
    });

    try {
        $callback();
        $output = ob_get_clean();

        rewind($outputBuffer);
        $output = stream_get_contents($outputBuffer);

        expect($output)->toContain('data:');
        expect($output)->toContain('"type"');
        expect($output)->toContain('"delta"');
    } finally {
        fclose($outputBuffer);
    }
});

it('asBroadcast dispatches events to specified channel', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Broadcast test'),
    ]);

    $channel = new PrivateChannel('test-channel');

    expect(fn () => Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test')
        ->asBroadcast($channel)
    )->not->toThrow(Exception::class);
});

it('asBroadcast accepts array of channels', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Multi-channel broadcast'),
    ]);

    $channels = [
        new PrivateChannel('channel-1'),
        new PrivateChannel('channel-2'),
    ];

    expect(fn () => Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asBroadcast($channels)
    )->not->toThrow(Exception::class);
});

it('streaming methods work with different providers', function (): void {
    $providers = [
        ['anthropic', 'claude-3-sonnet'],
        ['openai', 'gpt-4'],
        ['openai', 'gpt-4o'],
    ];

    foreach ($providers as [$provider, $model]) {
        Prism::fake([
            TextResponseFake::make()->withText('Provider test'),
        ]);

        $events = Prism::text()
            ->using($provider, $model)
            ->withPrompt('Test')
            ->asStream();

        $eventArray = iterator_to_array($events);

        expect($eventArray)->not->toBeEmpty();
        expect($eventArray[0])->toBeInstanceOf(StreamStartEvent::class);
        expect(end($eventArray))->toBeInstanceOf(StreamEndEvent::class);
    }
});

it('streaming with custom chunk size produces more or fewer events', function (): void {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('This is a test message'),
    ]);

    $fake->withFakeChunkSize(3);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $textDeltas = [];
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $textDeltas[] = $event;
        }
    }

    expect(count($textDeltas))->toBeGreaterThan(3);
});

it('asStream can be consumed multiple times with separate fake instances', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('First call'),
    ]);

    $events1 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asStream();
    $eventArray1 = iterator_to_array($events1);

    Prism::fake([
        TextResponseFake::make()->withText('Second call'),
    ]);

    $events2 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asStream();
    $eventArray2 = iterator_to_array($events2);

    expect($eventArray1)->not->toBeEmpty();
    expect($eventArray2)->not->toBeEmpty();
});

it('all stream events have valid IDs and timestamps', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('ID and timestamp test'),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    foreach ($events as $event) {
        expect($event->id)->toBeString();
        expect($event->id)->not->toBeEmpty();
        expect($event->timestamp)->toBeInt();
        expect($event->timestamp)->toBeGreaterThan(0);
    }
});

it('all stream events can be converted to array', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Array conversion test'),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    foreach ($events as $event) {
        $array = $event->toArray();
        expect($array)->toBeArray();
        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('timestamp');
    }
});

it('asStream handles finish reason content_filter correctly', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Response')
            ->withFinishReason(FinishReason::ContentFilter),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test')
        ->asStream();

    $endEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->not->toBeNull();
    expect($endEvent->finishReason)->toBe(FinishReason::ContentFilter);
});

it('asStream handles finish reason max_tokens correctly', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Response cut off due to max tokens')
            ->withFinishReason(FinishReason::Length),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $endEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->not->toBeNull();
    expect($endEvent->finishReason)->toBe(FinishReason::Length);
});

it('asStream handles finish reason tool_calls correctly', function (): void {
    $toolCall = new ToolCall('tool-1', 'calculator', ['op' => 'add']);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()->withToolCalls([$toolCall]),
            ]))
            ->withFinishReason(FinishReason::ToolCalls),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Calculate something')
        ->asStream();

    $endEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->not->toBeNull();
    expect($endEvent->finishReason)->toBe(FinishReason::ToolCalls);
});

it('asStream maintains message ID consistency across events', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $messageIds = [];
    foreach ($events as $event) {
        if ($event instanceof TextStartEvent || $event instanceof TextDeltaEvent || $event instanceof TextCompleteEvent) {
            $messageIds[] = $event->messageId;
        }
    }

    expect($messageIds)->not->toBeEmpty();
    $firstMessageId = $messageIds[0];
    foreach ($messageIds as $messageId) {
        expect($messageId)->toBe($firstMessageId);
    }
});

it('asStream handles very long text response', function (): void {
    $longText = str_repeat('This is a long text. ', 100);

    Prism::fake([
        TextResponseFake::make()->withText($longText),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Generate long text')
        ->asStream();

    $accumulatedText = '';
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $accumulatedText .= $event->delta;
        }
    }

    expect($accumulatedText)->toBe($longText);
});

it('asStream handles unicode and emoji in text', function (): void {
    $unicodeText = '🚀 Hello 世界! Héllo Wørld 🎉';

    Prism::fake([
        TextResponseFake::make()->withText($unicodeText),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test')
        ->asStream();

    $accumulatedText = '';
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $accumulatedText .= $event->delta;
        }
    }

    expect($accumulatedText)->toBe($unicodeText);
});

it('asStream handles special characters in text', function (): void {
    $specialText = 'Test with "quotes" and \'apostrophes\' and \n newlines \t tabs';

    Prism::fake([
        TextResponseFake::make()->withText($specialText),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $accumulatedText = '';
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $accumulatedText .= $event->delta;
        }
    }

    expect($accumulatedText)->toBe($specialText);
});

it('asStream handles responses with complex nested tool arguments', function (): void {
    $complexArgs = [
        'query' => 'search term',
        'options' => [
            'filters' => [
                'category' => ['tech', 'science'],
                'date' => ['from' => '2024-01-01', 'to' => '2024-12-31'],
            ],
            'limit' => 10,
            'sort' => ['field' => 'relevance', 'order' => 'desc'],
        ],
        'metadata' => null,
    ];

    $toolCall = new ToolCall('tool-123', 'complex_search', $complexArgs);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withToolCalls([$toolCall]),
        ])),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $toolCallEvents = [];
    foreach ($events as $event) {
        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
        }
    }

    expect($toolCallEvents)->toHaveCount(1);
    expect($toolCallEvents[0]->toolCall->arguments())->toBe($complexArgs);
});

it('asStream handles zero token usage', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Test')
            ->withUsage(new Usage(0, 0)),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $endEvent = null;
    foreach ($events as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->not->toBeNull();
    expect($endEvent->usage)->not->toBeNull();
    expect($endEvent->usage->promptTokens)->toBe(0);
    expect($endEvent->usage->completionTokens)->toBe(0);
});

it('asStream handles responses with only tool calls no text', function (): void {
    $toolCall = new ToolCall('tool-123', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $events = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Search')
        ->asStream();

    $hasTextDelta = false;
    $hasToolCall = false;

    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $hasTextDelta = true;
        }
        if ($event instanceof ToolCallEvent) {
            $hasToolCall = true;
        }
    }

    expect($hasToolCall)->toBeTrue();
    expect($hasTextDelta)->toBeFalse();
});

it('asStream maintains event ordering with multiple steps', function (): void {
    $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);
    $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test'], ['found' => true]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withText('First'),
            TextStepFake::make()->withToolCalls([$toolCall]),
            TextStepFake::make()->withToolResults([$toolResult]),
            TextStepFake::make()->withText('Last'),
        ])),
    ]);

    $events = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    $eventTypes = [];
    foreach ($events as $event) {
        $eventTypes[] = $event::class;
    }

    $streamStartIndex = array_search(StreamStartEvent::class, $eventTypes);
    $firstToolCallIndex = array_search(ToolCallEvent::class, $eventTypes);
    $toolResultIndex = array_search(ToolResultEvent::class, $eventTypes);
    $streamEndIndex = array_search(StreamEndEvent::class, $eventTypes);

    expect($streamStartIndex)->toBeLessThan($firstToolCallIndex);
    expect($firstToolCallIndex)->toBeLessThan($toolResultIndex);
    expect($toolResultIndex)->toBeLessThan($streamEndIndex);
});

it('asEventStreamResponse works after multiple fake setups', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('First'),
    ]);

    $response1 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asEventStreamResponse();

    Prism::fake([
        TextResponseFake::make()->withText('Second'),
    ]);

    $response2 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asEventStreamResponse();

    expect($response1)->toBeInstanceOf(StreamedResponse::class);
    expect($response2)->toBeInstanceOf(StreamedResponse::class);
});

it('asDataStreamResponse works after multiple fake setups', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('First'),
    ]);

    $response1 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asDataStreamResponse();

    Prism::fake([
        TextResponseFake::make()->withText('Second'),
    ]);

    $response2 = Prism::text()->using('openai', 'gpt-4')->withPrompt('Test')->asDataStreamResponse();

    expect($response1)->toBeInstanceOf(StreamedResponse::class);
    expect($response2)->toBeInstanceOf(StreamedResponse::class);
});

describe('step events', function (): void {
    it('emits step start and finish events for simple text response', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('Hello World'),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
        $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

        // Single response = 1 step
        expect($stepStartEvents)->toHaveCount(1);
        expect($stepFinishEvents)->toHaveCount(1);
    });

    it('emits step events in correct order relative to stream events', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('Test message'),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('anthropic', 'claude-3-sonnet')
                ->withPrompt('Test')
                ->asStream()
        );

        $eventTypes = array_map(fn (StreamEvent $e): string => $e::class, $events);

        $streamStartIdx = array_search(StreamStartEvent::class, $eventTypes);
        $stepStartIdx = array_search(StepStartEvent::class, $eventTypes);
        $stepFinishIdx = array_search(StepFinishEvent::class, $eventTypes);
        $streamEndIdx = array_search(StreamEndEvent::class, $eventTypes);

        // Order: StreamStart -> StepStart -> ... -> StepFinish -> StreamEnd
        expect($streamStartIdx)->toBeLessThan($stepStartIdx);
        expect($stepStartIdx)->toBeLessThan($stepFinishIdx);
        expect($stepFinishIdx)->toBeLessThan($streamEndIdx);
    });

    it('emits multiple step events for multi-step tool call conversation', function (): void {
        $toolCall = new ToolCall('tool-1', 'calculator', ['a' => 1, 'b' => 2]);
        $toolResult = new ToolResult('tool-1', 'calculator', ['a' => 1, 'b' => 2], ['result' => 3]);

        Prism::fake([
            TextResponseFake::make()->withSteps(collect([
                TextStepFake::make()
                    ->withText('Let me calculate')
                    ->withToolCalls([$toolCall]),
                TextStepFake::make()
                    ->withToolResults([$toolResult]),
                TextStepFake::make()
                    ->withText('The answer is 3'),
            ])),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('What is 1 + 2?')
                ->asStream()
        );

        $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
        $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

        // Multiple steps = multiple step events
        expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2);
        expect(count($stepFinishEvents))->toBeGreaterThanOrEqual(2);

        // Start and finish counts should match
        expect(count($stepStartEvents))->toBe(count($stepFinishEvents));
    });

    it('maintains step start/finish pairing for each step', function (): void {
        $toolCall = new ToolCall('tool-1', 'search', ['q' => 'test']);
        $toolResult = new ToolResult('tool-1', 'search', ['q' => 'test'], ['found' => true]);

        Prism::fake([
            TextResponseFake::make()->withSteps(collect([
                TextStepFake::make()->withToolCalls([$toolCall]),
                TextStepFake::make()->withToolResults([$toolResult]),
                TextStepFake::make()->withText('Done'),
            ])),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('anthropic', 'claude-3-sonnet')
                ->withPrompt('Search')
                ->asStream()
        );

        // Get indices of step events
        $stepStartIndices = array_keys(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent));
        $stepFinishIndices = array_keys(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));

        // Re-index
        $stepStartIndices = array_values($stepStartIndices);
        $stepFinishIndices = array_values($stepFinishIndices);

        // Each step start should be followed by its corresponding finish
        foreach ($stepStartIndices as $i => $startIdx) {
            if (isset($stepFinishIndices[$i])) {
                expect($startIdx)->toBeLessThan($stepFinishIndices[$i],
                    "Step $i: start index ($startIdx) should be less than finish index ({$stepFinishIndices[$i]})");
            }
        }
    });

    it('places tool events within step boundaries', function (): void {
        $toolCall = new ToolCall('tool-1', 'weather', ['city' => 'NYC']);
        $toolResult = new ToolResult('tool-1', 'weather', ['city' => 'NYC'], ['temp' => 72]);

        Prism::fake([
            TextResponseFake::make()->withSteps(collect([
                TextStepFake::make()
                    ->withText('Checking weather')
                    ->withToolCalls([$toolCall]),
                TextStepFake::make()
                    ->withToolResults([$toolResult]),
                TextStepFake::make()
                    ->withText('It is 72 degrees'),
            ])),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Weather in NYC?')
                ->asStream()
        );

        $getFirstIndex = fn (string $class): ?int => array_key_first(
            array_filter($events, fn (StreamEvent $e): bool => $e instanceof $class)
        );

        $stepStartIdx = $getFirstIndex(StepStartEvent::class);
        $toolCallIdx = $getFirstIndex(ToolCallEvent::class);
        $stepFinishIndices = array_keys(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));

        // Tool call should occur after first step start
        expect($toolCallIdx)->toBeGreaterThan($stepStartIdx);

        // Tool call should occur before first step finish
        expect($toolCallIdx)->toBeLessThan($stepFinishIndices[0]);
    });

    it('step events have valid id and timestamp', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('Test'),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepEvents = array_filter(
            $events,
            fn (StreamEvent $e): bool => $e instanceof StepStartEvent || $e instanceof StepFinishEvent
        );

        foreach ($stepEvents as $event) {
            expect($event->id)->toBeString()->not->toBeEmpty();
            expect($event->timestamp)->toBeInt()->toBeGreaterThan(0);
        }
    });

    it('step events can be converted to array', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('Test'),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepEvents = array_filter(
            $events,
            fn (StreamEvent $e): bool => $e instanceof StepStartEvent || $e instanceof StepFinishEvent
        );

        foreach ($stepEvents as $event) {
            $array = $event->toArray();
            expect($array)->toBeArray();
            expect($array)->toHaveKey('id');
            expect($array)->toHaveKey('timestamp');
        }
    });

    it('step finish event has default zero usage when fake response has no explicit usage', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('Test'),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepFinishEvents = array_values(array_filter(
            $events,
            fn (StreamEvent $e): bool => $e instanceof StepFinishEvent
        ));

        expect($stepFinishEvents)->not->toBeEmpty();
        expect($stepFinishEvents[0]->usage)->not->toBeNull();
        expect($stepFinishEvents[0]->usage->promptTokens)->toBe(0);
        expect($stepFinishEvents[0]->usage->completionTokens)->toBe(0);
    });

    it('step finish event contains usage when fake response has usage', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Test')
                ->withUsage(new Usage(100, 50)),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepFinishEvents = array_values(array_filter(
            $events,
            fn (StreamEvent $e): bool => $e instanceof StepFinishEvent
        ));

        expect($stepFinishEvents)->not->toBeEmpty();
        expect($stepFinishEvents[0]->usage)->not->toBeNull();
        expect($stepFinishEvents[0]->usage->promptTokens)->toBe(100);
        expect($stepFinishEvents[0]->usage->completionTokens)->toBe(50);
    });

    it('step finish event toArray includes usage when set', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Test')
                ->withUsage(new Usage(20, 10)),
        ]);

        $events = iterator_to_array(
            Prism::text()
                ->using('openai', 'gpt-4')
                ->withPrompt('Test')
                ->asStream()
        );

        $stepFinishEvents = array_values(array_filter(
            $events,
            fn (StreamEvent $e): bool => $e instanceof StepFinishEvent
        ));

        expect($stepFinishEvents)->not->toBeEmpty();
        $array = $stepFinishEvents[0]->toArray();
        expect($array)->toHaveKey('usage');
        expect($array['usage'])->not->toBeNull();
        expect($array['usage']['prompt_tokens'])->toBe(20);
        expect($array['usage']['completion_tokens'])->toBe(10);
    });

    it('step finish event toArray has null usage when event is constructed without usage', function (): void {
        $event = new StepFinishEvent(id: 'test-id', timestamp: 1234567890);
        $array = $event->toArray();
        expect($array)->toHaveKey('usage');
        expect($array['usage'])->toBeNull();
    });
});
