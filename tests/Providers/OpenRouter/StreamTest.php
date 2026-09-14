<?php

declare(strict_types=1);

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
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('can stream text with a prompt', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
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

    expect($events)->not->toBeEmpty();

    // Check first event is StreamStartEvent
    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[0]->model)->toBe('openai/gpt-4-turbo');
    expect($events[0]->provider)->toBe('openrouter');

    // Check we have TextStartEvent
    $textStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextStartEvent);
    expect($textStartEvents)->toHaveCount(1);

    // Check we have TextDeltaEvents
    $textDeltaEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextDeltaEvent);
    expect($textDeltaEvents)->not->toBeEmpty();

    // Check we have TextCompleteEvent
    $textCompleteEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextCompleteEvent);
    expect($textCompleteEvents)->toHaveCount(1);

    // Check last event is StreamEndEvent
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    expect($lastEvent->usage)->not->toBeNull();
    expect($lastEvent->usage->promptTokens)->toBe(7);
    expect($lastEvent->usage->completionTokens)->toBe(35);

    // Verify full text can be reconstructed
    expect($text)->toBe("Hello! I'm an AI assistant powered by OpenRouter. How can I help you today?");
});

it('forwards advanced provider options in streaming mode', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $stream = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Stream a short update.')
        // Confirm streaming payload honors OpenRouter streaming guidance https://openrouter.ai/docs/api-reference/streaming
        ->withProviderOptions([
            'stop' => ['END_STREAM'],
            'seed' => 11,
            'top_k' => 16,
            'frequency_penalty' => 0.4,
            'presence_penalty' => 0.15,
            'repetition_penalty' => 1.05,
            'min_p' => 0.05,
            'top_a' => 0.2,
            'logit_bias' => ['101' => -2],
            'logprobs' => false,
            'top_logprobs' => 2,
            'prediction' => [
                'type' => 'content',
                'content' => 'Update:',
            ],
            'transforms' => ['markdown'],
            'models' => ['openai/gpt-4-turbo', 'google/gemini-pro'],
            'route' => 'fallback',
            'provider' => ['require_parameters' => true],
            'user' => 'customer-stream-11',
            'parallel_tool_calls' => true,
            'verbosity' => 'low',
        ])
        ->asStream();

    // Prime the generator so the HTTP request is executed.
    foreach ($stream as $_event) {
        break;
    }

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $payload['stream'] === true
            && $payload['stop'] === ['END_STREAM']
            && $payload['seed'] === 11
            && $payload['top_k'] === 16
            && $payload['frequency_penalty'] === 0.4
            && $payload['presence_penalty'] === 0.15
            && $payload['repetition_penalty'] === 1.05
            && $payload['min_p'] === 0.05
            && $payload['top_a'] === 0.2
            && $payload['logit_bias'] === ['101' => -2]
            && $payload['logprobs'] === false
            && $payload['top_logprobs'] === 2
            && $payload['prediction'] === [
                'type' => 'content',
                'content' => 'Update:',
            ]
            && $payload['transforms'] === ['markdown']
            && $payload['models'] === ['openai/gpt-4-turbo', 'google/gemini-pro']
            && $payload['route'] === 'fallback'
            && $payload['provider'] === ['require_parameters' => true]
            && $payload['user'] === 'customer-stream-11'
            && $payload['parallel_tool_calls'] === true
            && $payload['verbosity'] === 'low'
            && $payload['stream_options'] === ['include_usage' => true];
    });
});

it('can stream text with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
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
            expect($event->toolCall->name)->toBe('weather');
            expect($event->toolCall->arguments())->toBe(['city' => 'San Francisco']);
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
            expect($event->toolResult->result)->toBe('The weather in San Francisco is 75°F and sunny');
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->toHaveCount(1);
    expect($toolResultEvents)->toHaveCount(1);

    // Verify text from first response
    expect($text)->toContain("I'll help you get the weather for you.");

    // Verify only one StreamStartEvent and one StreamEndEvent
    $streamStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);

    $lastStreamEnd = array_values($streamEndEvents)[array_key_last(array_values($streamEndEvents))];
    expect($lastStreamEnd->usage)->not->toBeNull();
    expect($lastStreamEnd->usage->promptTokens)->toBeGreaterThan(0);
    expect($lastStreamEnd->usage->completionTokens)->toBeGreaterThan(0);
});

it('can stream text with empty parameters tool calls when using gpt-5', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-empty-parameters-tools-when-using-gpt-5');

    $currentTime = '08:00:00';
    $timeTool = Tool::as('time')
        ->for('Get the current time')
        ->using(fn (): string => $currentTime);

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-5')
        ->withTools([$timeTool])
        ->withMaxSteps(3)
        ->withPrompt('Please tell me the current time, use the `time` tool')
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
            expect($event->toolCall->name)->toBe('time');
            expect($event->toolCall->arguments())->toBe([]);
        }

        if ($event instanceof ToolResultEvent) {
            $toolResultEvents[] = $event;
            expect($event->toolResult->result)->toContain($currentTime);
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallEvents)->toHaveCount(1);
    expect($toolResultEvents)->toHaveCount(1);
    expect($text)->toContain('The current time is '.$currentTime);

    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->not->toBeEmpty();
});

it('sends correct tool payload when streaming with a parameterless tool', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-parameterless-tool');

    $currentTime = '08:00:00';

    $timeTool = Tool::as('time')
        ->for('Get the current time')
        ->using(fn (): string => $currentTime);

    $searchTool = Tool::as('search')
        ->for('Search the web')
        ->withStringParameter('query', 'the search query')
        ->using(fn (string $query): string => 'results');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-5')
        ->withTools([$timeTool, $searchTool])
        ->withMaxSteps(3)
        ->withPrompt('What time is it?')
        ->asStream();

    $text = '';
    $toolCallEvents = [];

    foreach ($response as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof ToolCallEvent) {
            $toolCallEvents[] = $event;
        }
    }

    expect($toolCallEvents)->toHaveCount(1);
    expect($toolCallEvents[0]->toolCall->name)->toBe('time');
    expect($toolCallEvents[0]->toolCall->arguments())->toBe([]);
    expect($text)->toContain('The current time is '.$currentTime);

    Http::assertSent(function (Request $request): bool {
        $tools = $request->data()['tools'];

        // Tool without parameters: no 'parameters' key in function
        $timeFunction = $tools[0]['function'];
        expect($timeFunction['name'])->toBe('time');
        expect($timeFunction)->not->toHaveKey('parameters');

        // Tool with parameters keeps its 'parameters' key
        $searchFunction = $tools[1]['function'];
        expect($searchFunction['name'])->toBe('search');
        expect($searchFunction)->toHaveKey('parameters');
        expect($searchFunction['parameters']['properties'])->toHaveKey('query');

        return true;
    });
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-reasoning');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/o1-preview')
        ->withPrompt('Solve this math problem: 2 + 2 = ?')
        ->asStream();

    $events = [];
    $thinkingEvents = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ThinkingEvent) {
            $thinkingEvents[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();

    // Check for ThinkingStartEvent
    $thinkingStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof ThinkingStartEvent);
    expect($thinkingStartEvents)->toHaveCount(1);

    // Check for ThinkingEvent
    expect($thinkingEvents)->toHaveCount(1);
    expect($thinkingEvents[0]->delta)->toContain('math problem');

    // Check text was assembled
    expect($text)->toBe('The answer to 2 + 2 is 4.');

    // Check for usage with reasoning tokens
    $streamEndEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($streamEndEvents)->toHaveCount(1);

    $streamEndEvent = array_values($streamEndEvents)[0];
    expect($streamEndEvent->usage)->not->toBeNull();
    expect($streamEndEvent->usage->thoughtTokens)->toBe(12);

    expect($streamEndEvent->additionalContent)->toHaveKey('reasoning');
    expect($streamEndEvent->additionalContent['reasoning'])->toContain('math problem');
});

it('captures reasoning_details from streaming responses', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-reasoning-details');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-5')
        ->withPrompt('What is 2 + 2?')
        ->asStream();

    $events = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('The answer is 4.');

    $thinkingStartEvents = array_filter($events, fn (StreamEvent $e): bool => $e instanceof ThinkingStartEvent);
    expect($thinkingStartEvents)->toBeEmpty();

    $streamEndEvent = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent))[0];
    expect($streamEndEvent->additionalContent)->toHaveKey('reasoning_details');
    expect($streamEndEvent->additionalContent['reasoning_details'])->toHaveCount(1);
    expect($streamEndEvent->additionalContent['reasoning_details'][0]['type'])->toBe('reasoning.encrypted');
    expect($streamEndEvent->additionalContent['reasoning_details'][0]['id'])->toBe('rs_045e88aab38b');

    expect($streamEndEvent->usage->thoughtTokens)->toBe(15);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
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
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    // Extract step events
    $stepStartEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepStartEvent));
    $stepFinishEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StepFinishEvent));

    // Should have 2 steps: tool call step + final response step
    expect($stepStartEvents)->toHaveCount(2);
    expect($stepFinishEvents)->toHaveCount(2);

    // Verify step start/finish pairs are balanced
    expect(count($stepStartEvents))->toBe(count($stepFinishEvents));

    // Verify event ordering using indices
    $getIndices = fn (string $class): array => array_keys(array_filter($events, fn (StreamEvent $e): bool => $e instanceof $class));

    $streamStartIdx = $getIndices(StreamStartEvent::class)[0];
    $stepStartIndices = $getIndices(StepStartEvent::class);
    $stepFinishIndices = $getIndices(StepFinishEvent::class);
    $toolCallIndices = $getIndices(ToolCallEvent::class);
    $toolResultIndices = $getIndices(ToolResultEvent::class);
    $streamEndIdx = $getIndices(StreamEndEvent::class)[0];

    // Verify overall structure: StreamStart -> Steps -> StreamEnd
    expect($streamStartIdx)->toBeLessThan($stepStartIndices[0]);
    expect($stepFinishIndices[count($stepFinishIndices) - 1])->toBeLessThan($streamEndIdx);

    // Verify each step has proper start/finish ordering
    foreach ($stepStartIndices as $i => $startIdx) {
        expect($startIdx)->toBeLessThan($stepFinishIndices[$i], "Step $i: start should come before finish");
    }

    // Verify tool call happens within first step (before first step finish)
    expect($toolCallIndices[0])->toBeGreaterThan($stepStartIndices[0]);
    expect($toolCallIndices[0])->toBeLessThan($stepFinishIndices[0]);

    // Verify tool result happens after tool call but before second step starts
    expect($toolResultIndices[0])->toBeGreaterThan($toolCallIndices[0]);
    expect($toolResultIndices[0])->toBeLessThan($stepStartIndices[1]);
});

it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
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
