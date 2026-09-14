<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
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

    expect($events)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty();

    $eventTypes = array_map(get_class(...), $events);

    expect($eventTypes)->toContain(StreamStartEvent::class)
        ->and($eventTypes)->toContain(StreamEndEvent::class)
        ->and(count(array_filter($eventTypes, fn (string $t): bool => $t === TextDeltaEvent::class)))
        ->toBeGreaterThan(0);

    $lastEvent = end($events);

    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->finishReason)->toBeInstanceOf(FinishReason::class);

    $streamStartEvent = $events[array_search(StreamStartEvent::class, $eventTypes)];

    expect($streamStartEvent)->toBeInstanceOf(StreamStartEvent::class)
        ->and($streamStartEvent->model)->toBe('glm-5')
        ->and($streamStartEvent->provider)->toBe('z');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'glm-5';
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
        ->asStream();

    $events = [];
    $toolCallEvents = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
            expect($event->toolCall->name)
                ->toBeString()
                ->and($event->toolCall->name)->not->toBeEmpty();
        }

        if ($event instanceof ToolResultEvent) {
            expect($event->toolResult->result)->not->toBeEmpty();
        }
    }

    expect($events)->not->toBeEmpty()
        ->and($toolCallEvents)->not->toBeEmpty();

    $streamStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);

    expect($streamStartEvents)->toHaveCount(1)
        ->and($streamEndEvents)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'glm-5';
    });
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-max-tokens');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-system-prompt');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);

    expect($stepStartEvents)->toHaveCount(1);

    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    expect($stepFinishEvents)->toHaveCount(1);

    $eventTypes = array_map(get_class(...), $events);
    $streamStartIndex = array_search(StreamStartEvent::class, $eventTypes);
    $stepStartIndex = array_search(StepStartEvent::class, $eventTypes);
    $stepFinishIndex = array_search(StepFinishEvent::class, $eventTypes);
    $streamEndIndex = array_search(StreamEndEvent::class, $eventTypes);

    expect($streamStartIndex)->toBeLessThan($stepStartIndex)
        ->and($stepStartIndex)->toBeLessThan($stepFinishIndex)
        ->and($stepFinishIndex)->toBeLessThan($streamEndIndex);
});

it('emits multiple step events with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What is weather in Detroit?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent);

    expect(count($stepStartEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepFinishEvents))->toBeGreaterThanOrEqual(2)
        ->and(count($stepStartEvents))->toBe(count($stepFinishEvents));
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),

        Tool::as('search_games')
            ->for('useful for searching current games times in city')
            ->withStringParameter('city', 'The city that you want game times for')
            ->using(fn (string $city): string => "The tigers game is at 3pm in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});

it('handles specific tool choice in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-with-required-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Z, 'z-model')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice(ToolChoice::Auto)
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBeIn(['weather', 'search']);
});

it('throws PrismRateLimitedException for 429 in streaming', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Z, 'z-model')
        ->withPrompt('Who are you?')
        ->asStream();

    /** @phpstan-ignore-next-line */
    collect($response);

})->throws(PrismRateLimitedException::class);

it('excludes cached tokens from streamed promptTokens', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'z/stream-cached-tokens');

    $response = Prism::text()
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Who are you?')
        ->asStream();

    $endEvent = null;

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($endEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($endEvent->usage->promptTokens)->toBe(40)
        ->and($endEvent->usage->cacheReadInputTokens)->toBe(60);
});
