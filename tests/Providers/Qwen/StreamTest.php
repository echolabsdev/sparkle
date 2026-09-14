<?php

declare(strict_types=1);

namespace Prism\tests\Providers\Qwen;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.qwen.api_key', env('QWEN_API_KEY'));
    config()->set('prism.providers.qwen.url', env('QWEN_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
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
        ->and($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'text-generation/generation')
            && $body['model'] === 'qwen-plus';
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search_tool')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What is the weather in Detroit?')
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

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'text-generation/generation')
            && isset($body['parameters']['tools'])
            && $body['model'] === 'qwen-plus';
    });
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-max-tokens');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $event) {
        // Process stream
    }

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'text-generation/generation')
            && $body['parameters']['max_tokens'] === 1000;
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-system-prompt');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $event) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['input']['messages']) === 2
            && $body['input']['messages'][0]['role'] === 'system'
            && $body['input']['messages'][1]['role'] === 'user';
    });
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-with-reasoning');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwq-plus')
        ->withPrompt('Solve this complex math problem: What is 4 * 8?')
        ->asStream();

    $thinkingContent = '';
    $regularContent = '';
    $thinkingEvents = 0;
    $textDeltaEvents = 0;

    foreach ($response as $event) {
        if ($event instanceof ThinkingEvent) {
            $thinkingContent .= $event->delta;
            $thinkingEvents++;
        } elseif ($event instanceof TextDeltaEvent) {
            $regularContent .= $event->delta;
            $textDeltaEvents++;
        }
    }

    expect($thinkingEvents)
        ->toBeGreaterThan(0)
        ->and($textDeltaEvents)->toBeGreaterThan(0)
        ->and($thinkingContent)->not
        ->toBeEmpty()
        ->and($regularContent)->not
        ->toBeEmpty()
        ->and($thinkingContent)->toContain('answer')
        ->and($regularContent)->toContain('32');

    Http::assertSent(function (Request $request): bool {
        $data = json_decode($request->body(), true);

        return str_contains($request->url(), 'text-generation/generation')
            && $data['model'] === 'qwq-plus'
            && $data['parameters']['result_format'] === 'message'
            && $data['parameters']['incremental_output'] === true;
    });
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
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
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
        Tool::as('search_tool')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
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
    FixtureResponse::fakeStreamResponses('text-generation/generation', 'qwen/stream-with-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search_tool')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});
