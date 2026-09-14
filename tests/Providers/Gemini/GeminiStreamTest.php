<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

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
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ProviderTool;
use stdClass;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'sss-1234567890'));
});

it('can generate text stream with a basic prompt', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $origModel = 'gemini-2.0-flash';
    $response = Prism::text()
        ->using(Provider::Gemini, $origModel)
        ->withPrompt('Explain how AI works')
        ->asStream();

    $text = '';
    $events = [];
    $model = null;

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof StreamStartEvent) {
            $model = $event->model;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    expect($model)->toEqual($origModel);

    expect($text)->toContain(
        'AI? It\'s simple! We just feed a computer a HUGE pile of information, tell it to find patterns, and then it pretends to be smart! Like teaching a parrot to say cool things. Mostly magic, though.'
    );

    // Verify usage information in the final event
    expect($lastEvent->usage)
        ->not->toBeNull()
        ->and($lastEvent->usage->promptTokens)->toBe(21)
        ->and($lastEvent->usage->completionTokens)->toBe(47);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('can generate text stream using searchGrounding', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools-search-grounding');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withProviderOptions(['searchGrounding' => true])
        ->withMaxSteps(4)
        ->withPrompt('What\'s the current weather in San Francisco? And tell me if I need to wear a coat?')
        ->asStream();

    $text = '';
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
            expect($event->toolCall->name)->not->toBeEmpty();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }
    }

    // Verify that the request was sent with the correct tools configuration
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        // Verify the endpoint is for streaming
        $endpointCorrect = str_contains($request->url(), 'streamGenerateContent?alt=sse');

        // Verify tools configuration has google_search when searchGrounding is true
        $hasGoogleSearch = isset($data['tools']) &&
            isset($data['tools'][0]['google_search']) &&
            $data['tools'][0]['google_search'] instanceof stdClass;

        // Verify tools are configured as expected (google_search, not function_declarations)
        $toolsConfigCorrect = ! isset($data['tools'][0]['function_declarations']);

        return $endpointCorrect && $hasGoogleSearch && $toolsConfigCorrect;
    });

    expect($events)->not->toBeEmpty();
    expect($text)->toContain('The current weather in San Francisco is cloudy with a temperature of 56°F (13°C), and it feels like 54°F (12°C). There\'s a 0% chance of rain currently, though light rain is forecast for today and tonight with a 20% chance.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->usage->promptTokens)->toBe(22);
    expect($lastEvent->usage->completionTokens)->toBe(161);
});

it('can generate text stream using tools ', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools-thought');

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
        ->using(Provider::Gemini, 'gemini-3-pro-preview')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What\'s the current weather in San Francisco? And tell me if I need to wear a coat?')
        ->asStream();

    $text = '';
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }
    }

    expect($events)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($toolCalls)->not->toBeEmpty()
        ->and($toolCalls[0]->name)->toBe('weather')
        ->and($toolCalls[0]->arguments())->toBe(['city' => 'San Francisco'])
        ->and($toolCalls[0]->reasoningId)->not->toBeNull()
        ->and($toolCalls[0]->reasoningId)->toBe('Eq0ECqoEAdHtim8E9iAxfKwT6QsjWgFpC3mNjNoEc0uf/khdTIkry0wbRzOTpYuw1HdLFm5263kddqUYf+HKlTGq5fbXQb8e+MyBxsft/WzOcmMKTGxbnW1Nx7JPsMhu9TQltjp0w+EIOd7CSJnIcubiZ13tzGR7MOF8OIzTXidrdtNWRRND8kYKIMIBbW2EWuE2CJUzihJFct9JQSQulq/WpJ1ctiI1bl89HcoIGXTuTNK90CncMw/+ink6edobepVG4umPGIdgx2B6bE9uchv+kjKWSwnDsY5hvUP/uSseFZ5fpZbsrhB3IAMVrLBtFTKiLkuvkUh664EQ91rgfYGJ2NTu3SwpEfLy3ftUxqI1d/t84lMWo9X0om5ihM4sFpD//DxGeEKbs3XtAPEJoWawy24aXoVQb59SSt23Yr87epA261b8a2pDPW7QnUCg4GWSquAZ8z39BxO3DJ4fyU72QpRzs9m3G5XYt5iV8+ndMHjJsIxmeXYqqteq3QCNLbAwKCBLbpq4HyYgyu7R4RpnUEx1t8/3seXPfhEUSaP5Prjr9TEwdOB/fgig2BV2eJ4AuAvbw4A7/RkkBhvUQ+0KW3HByDBN5g8X59K5S3fasUhcDRU4QsGQOh9DShH2bi+o71SWpRw5zdKT3AmdDEQqrg5ybVK+plpA6XLSmDIekNl4lqn0YsUzPtzCdvD0rlI1OP85jNnYwQeRS1Dbm8viYbGdZWjTehd+jK1xIxU=')
        ->and($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->result)->toBe('The weather will be 75° and sunny in San Francisco')
        ->and($text)->toContain('The current weather in San Francisco is 75°F and sunny. You likely won\'t need a coat, but you might want to bring a light jacket just in case it gets breezy or cools down later.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->usage->promptTokens)->toBe(278);
    expect($lastEvent->usage->completionTokens)->toBe(44);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'streamGenerateContent?alt=sse')
        && isset($request->data()['contents']));
});

it('can generate text stream using file_search provider tool with options', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withPrompt('What are the main topics in the documents?')
        ->withProviderTools([
            new ProviderTool(
                type: 'file_search',
                name: 'file_search',
                options: [
                    'file_search_store_names' => ['fileSearchStores/test-store-456'],
                ]
            ),
        ])
        ->asStream();

    // Consume the stream
    foreach ($response as $event) {
        // Just consume events
    }

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['tools'][0])->toHaveKey('file_search');
        // A provider tool's options are a MAP, so they go out as a JSON object
        // even when empty — see Prism\Prism\Support\JsonMap.
        expect($data['tools'][0]['file_search'])->toBeInstanceOf(stdClass::class);
        expect($data['tools'][0]['file_search'])->toHaveProperty('file_search_store_names');
        expect($data['tools'][0]['file_search']->file_search_store_names)->toBe(['fileSearchStores/test-store-456']);

        return true;
    });
});

it('yields ToolCall events before ToolResult events', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What\'s the current weather in San Francisco?')
        ->asStream();

    $events = [];
    $eventOrder = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $eventOrder[] = 'ToolCall';
        }

        if ($event instanceof ToolResultEvent) {
            $eventOrder[] = 'ToolResult';
        }
    }

    expect($eventOrder)
        ->not->toBeEmpty()
        ->and($eventOrder[0])->toBe('ToolCall')
        ->and($eventOrder[1])->toBe('ToolResult');

    $toolCallEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent);
    $toolResultEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent);
    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);

    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolResultEvents)->not->toBeEmpty();

    // Verify only one StreamStartEvent and one StreamEndEvent
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    $firstToolCallEvent = array_values($toolCallEvents)[0];
    expect($firstToolCallEvent->toolCall)->not->toBeNull();
    // Verify reasoningId property exists and has a value
    expect($firstToolCallEvent->toolCall->reasoningId)->not->toBeNull()
        ->and($firstToolCallEvent->toolCall->reasoningId)->toBe('thought_abc123');

    $firstToolResultEvent = array_values($toolResultEvents)[0];
    expect($firstToolResultEvent->toolResult)->not->toBeNull();
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withPrompt('Explain how AI works')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Check for StepStartEvent after StreamStartEvent
    $stepStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent);
    expect($stepStartEvents)->toHaveCount(1);

    // Check for StepFinishEvent before StreamEndEvent
    $stepFinishEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));
    expect($stepFinishEvents)->toHaveCount(1);

    // Verify StepFinishEvent contains usage data
    expect($stepFinishEvents[0]->usage)->not->toBeNull();
    expect($stepFinishEvents[0]->usage->promptTokens)->toBe(21);
    expect($stepFinishEvents[0]->usage->completionTokens)->toBe(47);

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
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
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
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(1)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
});

it('can generate text stream using multiple parallel tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-multiple-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be '.($city === 'San Francisco' ? 50 : 75)."° and sunny in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withTools($tools)
        ->withMaxSteps(5)
        ->withPrompt('Is it warmer in San Francisco or Santa Cruz? What\'s the weather like in both cities?')
        ->asStream();

    $text = '';
    $events = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCalls[] = $event->toolCall;
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event->toolResult;
        }
    }

    expect($events)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($toolCalls)->not->toBeEmpty()
        ->and(count($toolCalls))->toBe(2)
        ->and(count($toolResults))->toBe(2)
        ->and($text)->toContain('50')
        ->and($text)->toContain('75');

    // Both tool calls should have reasoningId from the first tool call's thoughtSignature
    expect($toolCalls[0]->reasoningId)->not->toBeNull();
    expect($toolCalls[1]->reasoningId)->not->toBeNull();
    expect($toolCalls[0]->reasoningId)->toBe($toolCalls[1]->reasoningId);
});

it('sends topK in generationConfig for streaming', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $events = [];
    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withPrompt('Explain how AI works')
        ->usingTopK(40)
        ->asStream();

    foreach ($response as $event) {
        $events[] = $event;
    }

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data['generationConfig'])
            ->toHaveKey('topK')
            ->and($data['generationConfig']['topK'])->toBe(40);

        return true;
    });
});

it('passes service_tier in the request body for streaming', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withPrompt('Summarize this document.')
        ->withProviderOptions(['serviceTier' => 'flex'])
        ->asStream();

    // Consume the stream
    foreach ($response as $event) {
        //
    }

    Http::assertSent(function (Request $request): true {
        $data = $request->data();

        expect($data)->toHaveKey('service_tier')
            ->and($data['service_tier'])->toBe('flex');

        return true;
    });
});

it('keeps tools a JSON array when streaming with provider and custom tools', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/stream-with-tools-search-grounding');

    $tools = [
        Tool::as('search_games')
            ->for('useful for searching current games times in the city')
            ->withStringParameter('city', 'The city that you want the game times for')
            ->using(fn (string $city): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-2.5-flash')
        ->withMaxSteps(1)
        ->withTools($tools)
        ->withProviderTools([new ProviderTool('google_search')])
        ->withPrompt('What sport fixtures are on today?')
        ->asStream();

    foreach ($response as $event) {
        // drain the stream so the request is actually dispatched
    }

    Http::assertSent(function (Request $request): true {
        $tools = $request->data()['tools'];

        // Grounding + custom tools together must stay Tool[] (a JSON array),
        // not a mixed-key array json_encode would emit as an object.
        expect(array_is_list($tools))->toBeTrue();
        expect($tools[0])->toHaveKey('google_search');
        expect($tools[1])->toHaveKey('function_declarations');

        return true;
    });
});
