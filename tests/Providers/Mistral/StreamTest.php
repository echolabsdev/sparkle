<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'test-key-12345'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain(
            'large language model'
        );

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The Tigers game today is at 3pm in Detroit.'),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withMessages([
            new UserMessage('What time is the tigers game today and should I wear a coat?'),
        ])
        ->asStream();

    $text = '';
    $events = [];
    $toolCallEvents = [];
    $toolResultEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
            expect($event->toolCall->name)
                ->toBeString()
                ->and($event->toolCall->name)->not
                ->toBeEmpty()
                ->and($event->toolCall->arguments())->toBeArray();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
        }

        if ($event instanceof StreamEndEvent) {
            expect($event->finishReason)->toBeInstanceOf(FinishReason::class);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);
});

it('handles maximum tool call depth exceeded', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('A tool that will be called recursively')
            ->withStringParameter('input', 'Any input')
            ->using(fn (string $input): string => 'This is a recursive response that will trigger another tool call.'),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(0)
        ->withPrompt('Call the weather tool multiple times')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismException::class);
    expect($exception->getMessage())->toContain('Maximum tool call chain depth exceeded');
});

it('handles invalid stream data correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {invalid-json}\n\ndata: more invalid data\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('This will trigger invalid JSON')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismStreamDecodeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismStreamDecodeException::class);
});

it('respects system prompts in the requests', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $systemPrompt = 'You are a helpful assistant.';

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withSystemPrompt($systemPrompt)
        ->withPrompt('Say hello')
        ->asStream()
        ->current();

    Http::assertSent(function ($request) use ($systemPrompt): bool {
        $data = $request->data();

        $hasSystemPrompt = false;
        foreach ($data['messages'] as $message) {
            if ($message['role'] === 'system' && $message['content'] === $systemPrompt) {
                $hasSystemPrompt = true;
                break;
            }
        }

        return $hasSystemPrompt;
    });
});

it('verifies correct request structure for streaming', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Test')
        ->asStream()
        ->current();

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        expect($data['stream'])->toBe(true);
        expect($data['model'])->toBe('mistral-small-latest');
        expect($data['messages'])->toBeArray();
        expect($data['messages'][0]['role'])->toBe('user');
        expect($data['messages'][0]['content'])->toBeArray();
        expect($data['messages'][0]['content'][0]['type'])->toBe('text');
        expect($data['messages'][0]['content'][0]['text'])->toBe('Test');

        return true;
    });
});

it('forwards reasoning_effort provider option for streaming requests', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: {\"choices\": [{\"delta\": {\"content\": \"Hello\"}}]}\n\ndata: {\"choices\": [{\"delta\": {\"content\": \" world\"}, \"finish_reason\": \"stop\"}]}\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Test')
        ->withProviderOptions([
            'reasoning_effort' => 'high',
        ])
        ->asStream()
        ->current();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return isset($data['reasoning_effort'])
            && $data['reasoning_effort'] === 'high';
    });
});

it('can handle event types correctly', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('search')
            ->for('A search tool')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Search results for {$query}"),
        Tool::as('weather')
            ->for('A weather tool')
            ->withStringParameter('city', 'The city name')
            ->using(fn (string $city): string => "Weather in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('Get weather for Detroit')
        ->asStream();

    $hasToolCallEvent = false;
    $hasToolResultEvent = false;

    foreach ($response as $event) {
        if ($event instanceof ToolCallEvent) {
            $hasToolCallEvent = true;
        }

        if ($event instanceof ToolResultEvent) {
            $hasToolResultEvent = true;
        }
    }

    expect($hasToolCallEvent)->toBe(true);
    expect($hasToolResultEvent)->toBe(true);
});

it('handles rate limiting correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            'Too many requests',
            429,
            [
                'x-ratelimit-limit-requests' => '500',
                'x-ratelimit-remaining-requests' => '0',
                'x-ratelimit-reset-requests' => '1m',
                'retry-after' => '60',
            ]
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('This will trigger rate limiting')
        ->asStream();

    $exception = null;

    try {
        foreach ($response as $chunk) {
            // The test should throw before completing
        }
    } catch (PrismRateLimitedException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(PrismRateLimitedException::class);
});

it('handles empty stream response correctly', function (): void {
    Http::fake([
        '*' => Http::response(
            "data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Empty response')
        ->asStream();

    $chunks = [];
    foreach ($response as $chunk) {
        $chunks[] = $chunk;
    }

    expect($chunks)->toBeArray();
});

it('includes correct token counts in StreamEndEvent', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Who are you?')
        ->asStream();

    $streamEndEvent = null;

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($streamEndEvent)->not->toBeNull();
    expect($streamEndEvent->usage)->not->toBeNull();
    expect($streamEndEvent->usage->promptTokens)->toBe(7);
    expect($streamEndEvent->usage->completionTokens)->toBe(13);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-basic-text-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-small-latest')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Check for StepStartEvent after StreamStartEvent
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    expect($stepStartEvents)->toHaveCount(1);

    // Check for StepFinishEvent before StreamEndEvent
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);
    expect($stepFinishEvents)->toHaveCount(1);

    // Verify order: StreamStart -> StepStart -> ... -> StepFinish -> StreamEnd
    $eventTypes = array_map(get_class(...), $events);
    $streamStartIndex = array_search(StreamStartEvent::class, $eventTypes);
    $stepStartIndex = array_search(StepStartEvent::class, $eventTypes);
    $stepFinishIndex = array_search(StepFinishEvent::class, $eventTypes);
    $streamEndIndex = array_search(StreamEndEvent::class, $eventTypes);

    expect($streamStartIndex)->toBeLessThan($stepStartIndex);
    expect($stepStartIndex)->toBeLessThan($stepFinishIndex);
    expect($stepFinishIndex)->toBeLessThan($streamEndIndex);
});

it('emits multiple step events with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What is the weather in Detroit?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // With tool calls, we should have multiple step start/finish pairs
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    // At least 2 steps: one for tool call, one for final response
    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2);
    expect(count($stepFinishEvents))->toBeGreaterThanOrEqual(2);

    // Verify step start/finish pairs are balanced
    expect(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-with-tools-1');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Mistral, 'mistral-large-latest')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What is the weather in Detroit?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});

it('can stream with reasoning models', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'mistral/stream-reasoning-model-1');

    $response = Prism::text()
        ->using(Provider::Mistral, 'magistral-small-latest')
        ->withPrompt('What is 2+2?')
        ->asStream();

    $text = '';
    $thinking = '';
    $events = [];
    $thinkingStartEvents = [];
    $thinkingEvents = [];
    $thinkingCompleteEvents = [];
    $textStartEvents = [];
    $textDeltaEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ThinkingStartEvent) {
            $thinkingStartEvents[] = $event;
        }

        if ($event instanceof ThinkingEvent) {
            $thinkingEvents[] = $event;
            $thinking .= $event->delta;
        }

        if ($event instanceof ThinkingCompleteEvent) {
            $thinkingCompleteEvents[] = $event;
        }

        if ($event instanceof TextStartEvent) {
            $textStartEvents[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $textDeltaEvents[] = $event;
            $text .= $event->delta;
        }
    }

    // Verify thinking events
    expect($thinkingStartEvents)->toHaveCount(1)
        ->and($thinkingEvents)->not->toBeEmpty()
        ->and($thinkingCompleteEvents)->toHaveCount(1)
        ->and($thinking)->not->toBeEmpty();

    // Verify text events
    expect($textStartEvents)->toHaveCount(1)
        ->and($textDeltaEvents)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty();

    // Verify event ordering: ThinkingStart -> ThinkingEvents -> ThinkingComplete -> TextStart -> TextDelta
    $eventTypes = array_map(get_class(...), $events);
    $thinkingStartIndex = array_search(ThinkingStartEvent::class, $eventTypes);
    $thinkingCompleteIndex = array_search(ThinkingCompleteEvent::class, $eventTypes);
    $textStartIndex = array_search(TextStartEvent::class, $eventTypes);

    expect($thinkingStartIndex)->toBeLessThan($thinkingCompleteIndex)
        ->and($thinkingCompleteIndex)->toBeLessThan($textStartIndex);

    // Verify stream ends properly
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
});
