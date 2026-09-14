<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ProviderTool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-20250219')
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
    expect($text)->not->toBeEmpty();

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

it('can return usage with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-20250219')
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

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect((array) $lastEvent->usage)->toBe([
        'promptTokens' => 11,
        'completionTokens' => 104,
        'cacheWriteInputTokens' => 0,
        'cacheReadInputTokens' => 0,
        'thoughtTokens' => null,
        'cost' => null,
    ]);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

describe('tools', function (): void {
    it('can generate text using tools with streaming', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStream();

        $text = '';
        $events = [];
        $toolCallFound = false;
        $toolResults = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ToolCallEvent) {
                $toolCallFound = true;
                expect($event->toolCall->name)->not->toBeEmpty();
                expect($event->toolCall->arguments())->toBeArray();
            }

            if ($event instanceof ToolResultEvent) {
                $toolResults[] = $event;
            }

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

        // Verify only one StreamStartEvent and one StreamEndEvent
        $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
        $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);
        expect($streamStartEvents)->toHaveCount(1);
        expect($streamEndEvents)->toHaveCount(1);

        // Verify the HTTP request
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && isset($body['tools'])
                && $body['stream'] === true;
        });
    });

    it('can process a complete conversation with multiple tool calls', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-multi-tool-conversation');

        $tools = [
            Tool::as('weather')
                ->for('Get weather information')
                ->withStringParameter('city', 'City name')
                ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

            Tool::as('search')
                ->for('Search for information')
                ->withStringParameter('query', 'The search query')
                ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
        ];

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(5) // Allow multiple tool call rounds
            ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
            ->asStream();

        $fullResponse = '';
        $toolCallCount = 0;

        foreach ($response as $event) {
            if ($event instanceof ToolCallEvent) {
                $toolCallCount++;
            }

            if ($event instanceof TextDeltaEvent) {
                $fullResponse .= $event->delta;
            }
        }

        expect($toolCallCount)->toBeGreaterThanOrEqual(1);
        expect($fullResponse)->not->toBeEmpty();

        // Verify we made multiple requests for a conversation with tool calls
        Http::assertSentCount(3);
    });

    it('emits individual ToolCall and ToolResult events during streaming', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStream();

        $events = [];
        $toolCallEvents = [];
        $toolResultEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ToolCallEvent) {
                $toolCallEvents[] = $event;
            }

            if ($event instanceof ToolResultEvent) {
                $toolResultEvents[] = $event;
            }
        }

        expect($events)->not->toBeEmpty();
        expect($toolCallEvents)->not->toBeEmpty('Expected to find at least one ToolCall event');
        expect($toolResultEvents)->not->toBeEmpty('Expected to find at least one ToolResult event');

        // Verify ToolCall events have the expected structure
        $firstToolCallEvent = $toolCallEvents[0];
        expect($firstToolCallEvent->toolCall->name)->not->toBeEmpty();
        expect($firstToolCallEvent->toolCall->arguments())->toBeArray();
        expect($firstToolCallEvent->toolCall->id)->not->toBeEmpty();

        // Verify ToolResult events have the expected structure
        $firstToolResultEvent = $toolResultEvents[0];
        expect($firstToolResultEvent->toolResult->result)->not->toBeEmpty();
        expect($firstToolResultEvent->success)->toBeTrue();
    });

    it('emits ToolCallDelta events during streaming', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStream();

        $toolCallDeltaEvents = [];

        foreach ($response as $event) {
            if ($event instanceof ToolCallDeltaEvent) {
                $toolCallDeltaEvents[] = $event;
            }
        }

        expect($toolCallDeltaEvents)->not->toBeEmpty('Expected to find at least one ToolCallDeltaEvent event');

        // Verify ToolCallDelta events have the expected structure
        $firstToolCallDeltaEvent = $toolCallDeltaEvents[0];
        expect($firstToolCallDeltaEvent->id)->not->toBeEmpty();
        expect($firstToolCallDeltaEvent->timestamp)->not->toBeEmpty()->toBeInt();
        expect($firstToolCallDeltaEvent->toolId)->not->toBeEmpty();
        expect($firstToolCallDeltaEvent->toolName)->not->toBeEmpty();
        expect($firstToolCallDeltaEvent->delta)->toBeString();

        // Verify concatenated deltas from ToolCallDelta events of the first tool call is a valid json
        $paramsJsonString = collect($toolCallDeltaEvents)
            ->filter(fn (ToolCallDeltaEvent $event): bool => $event->toolName === $firstToolCallDeltaEvent->toolName)
            ->map(fn (ToolCallDeltaEvent $event): string => $event->delta)
            ->join('');

        expect($paramsJsonString)->toBeJson();
    });

    it('sends StreamEndEvent using tools with streaming and max steps = 1', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
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
});

describe('provider tools', function (): void {
    it('handles provider tool calls and provider tool results in stream end event', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('Get me the latest stock price for AAPL and the weather in New York City.')
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->asStream();

        $providerToolUses = [];
        $providerToolResults = [];

        foreach ($response as $event) {
            if ($event instanceof ProviderToolEvent) {
                if ($event->status === 'completed') {
                    if (in_array($event->toolType, ['web_search', 'web_fetch'])) {
                        $providerToolUses[] = $event->data;
                    }
                } elseif ($event->status === 'result_received') {
                    $providerToolResults[] = $event->data;
                }
            }
        }

        // Check that provider tool calls are included in the additional content
        expect(isset($providerToolUses))->toBeTrue();
        expect(count($providerToolUses))->toBeGreaterThanOrEqual(1);
        expect($providerToolUses[0]['type'])->toBe('server_tool_use');
        expect($providerToolUses[0]['name'])->toBe('web_search');

        // Check that provider tool results are included in the additional content
        expect(isset($providerToolResults))->toBeTrue();
        expect(count($providerToolResults))->toBeGreaterThanOrEqual(1);
        expect($providerToolResults[0]['type'])->toBe('web_search_tool_result');
        expect($providerToolResults[0]['content'])->toBeArray();
        expect($providerToolResults[0]['tool_use_id'])->toBe($providerToolUses[0]['id']);
    });

    it('preserves server tool content blocks during multi-step tool loop with web search', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-web-search-and-tool-call');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 21° and partly cloudy'),
        ];

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-haiku-20241022')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in London?')
            ->asStream();

        $text = '';
        $events = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        // The tool loop should complete without a "Could not find search result for citation index" error
        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
        expect($text)->toContain('18°C');

        // Verify the second HTTP request includes server tool blocks in the replayed assistant message
        $requests = Http::recorded();
        expect($requests)->toHaveCount(2);

        $secondRequestPayload = $requests[1][0]->data();
        $assistantMessage = collect($secondRequestPayload['messages'])
            ->first(fn (array $msg): bool => $msg['role'] === 'assistant');

        $contentTypes = array_column($assistantMessage['content'], 'type');
        expect($contentTypes)->toContain('server_tool_use');
        expect($contentTypes)->toContain('web_search_tool_result');
        expect($contentTypes)->toContain('text');
        expect($contentTypes)->toContain('tool_use');
    });
});

describe('citations', function (): void {
    it('emits CitationEvent and includes citations in StreamEndEvent', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withProviderOptions(['citations' => true])
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
        expect($citationEvents[0]->messageId)->not->toBeEmpty();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->toBeArray();
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->citations[0]->citations[0])->toBeInstanceOf(Citation::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('emits citation events with proper message and block context', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withProviderOptions(['citations' => true])
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->asStream();

        $citationEvents = [];

        foreach ($response as $event) {
            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        // Verify citation events have proper context
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0]->messageId)->not->toBeEmpty();
        expect($citationEvents[0]->blockIndex)->toBeInt();
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);
    });

    it('handles web search citations in events', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('What is the weather like in London UK today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->asStream();

        $text = '';
        $events = [];
        $citationEvents = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }

            if ($event instanceof CitationEvent) {
                $citationEvents[] = $event;
            }
        }

        $lastEvent = end($events);

        // Check that citation events were emitted for web search results
        expect($citationEvents)->not->toBeEmpty();
        expect($citationEvents[0])->toBeInstanceOf(CitationEvent::class);
        expect($citationEvents[0]->citation)->toBeInstanceOf(Citation::class);

        // Verify web search citations have URL source type
        expect($citationEvents[0]->citation->sourceType->value)->toBe('url');
        expect($citationEvents[0]->citation->source)->toBeString();

        // Check that the StreamEndEvent contains citations
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->not->toBeEmpty();
        expect($lastEvent->citations[0])->toBeInstanceOf(MessagePartWithCitations::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });
});

describe('thinking', function (): void {
    describe('adaptive', function (): void {
        it('yields thinking events', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
                ->asStream();

            $events = collect($response);

            expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingStart)->sole())
                ->toBeInstanceOf(ThinkingStartEvent::class);

            $thinkingDeltas = $events->where(
                fn (StreamEvent $event): bool => $event->type() === StreamEventType::ThinkingDelta
            );

            $thinkingDeltas
                ->each(function (StreamEvent $event): void {
                    expect($event)->toBeInstanceOf(ThinkingEvent::class);
                });

            expect($thinkingDeltas->count())->toBeGreaterThan(10);

            expect($thinkingDeltas->first()->delta)->not->toBeEmpty();

            expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingComplete)->sole())
                ->toBeInstanceOf(ThinkingCompleteEvent::class);
        });

        it('sends adaptive thinking payload in stream request', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
                ->asStream();

            collect($response);

            Http::assertSent(function (Request $request): bool {
                $body = json_decode($request->body(), true);

                return isset($body['thinking'])
                    && $body['thinking']['type'] === 'adaptive'
                    && ! isset($body['thinking']['budget_tokens']);
            });
        });

        it('sends effort in stream request', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions([
                    'thinking' => ['type' => 'adaptive'],
                    'effort' => 'medium',
                ])
                ->asStream();

            collect($response);

            Http::assertSent(function (Request $request): bool {
                $body = json_decode($request->body(), true);

                return isset($body['output_config']['effort'])
                    && $body['output_config']['effort'] === 'medium';
            });
        });

        it('includes thinking_signature in StreamEndEvent additionalContent', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
                ->asStream();

            $events = collect($response);

            $streamEndEvent = $events->last();

            expect($streamEndEvent)->toBeInstanceOf(StreamEndEvent::class);
            expect($streamEndEvent->additionalContent)->toHaveKey('thinking');
            expect($streamEndEvent->additionalContent['thinking'])->not->toBeEmpty();
            expect($streamEndEvent->additionalContent)->toHaveKey('thinking_signature');
            expect($streamEndEvent->additionalContent['thinking_signature'])->not->toBeEmpty();
        });
    });

    describe('legacy', function (): void {
        it('yields thinking events', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions(['thinking' => ['enabled' => true]])
                ->asStream();

            $events = collect($response);

            expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingStart)->sole())
                ->toBeInstanceOf(ThinkingStartEvent::class);

            $thinkingDeltas = $events->where(
                fn (StreamEvent $event): bool => $event->type() === StreamEventType::ThinkingDelta
            );

            $thinkingDeltas
                ->each(function (StreamEvent $event): void {
                    expect($event)->toBeInstanceOf(ThinkingEvent::class);
                });

            expect($thinkingDeltas->count())->toBeGreaterThan(10);

            expect($thinkingDeltas->first()->delta)->not->toBeEmpty();

            expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingComplete)->sole())
                ->toBeInstanceOf(ThinkingCompleteEvent::class);
        });

        it('can process streams with thinking enabled with custom budget', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $customBudget = 2048;
            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions([
                    'thinking' => [
                        'enabled' => true,
                        'budgetTokens' => $customBudget,
                    ],
                ])
                ->asStream();

            collect($response);

            Http::assertSent(function (Request $request) use ($customBudget): bool {
                $body = json_decode($request->body(), true);

                return isset($body['thinking'])
                    && $body['thinking']['type'] === 'enabled'
                    && $body['thinking']['budget_tokens'] === $customBudget;
            });
        });

        it('includes thinking_signature in StreamEndEvent additionalContent', function (): void {
            FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
                ->withPrompt('What is the meaning of life?')
                ->withProviderOptions(['thinking' => ['enabled' => true]])
                ->asStream();

            $events = collect($response);

            $streamEndEvent = $events->last();

            expect($streamEndEvent)->toBeInstanceOf(StreamEndEvent::class);
            expect($streamEndEvent->additionalContent)->toHaveKey('thinking');
            expect($streamEndEvent->additionalContent['thinking'])->not->toBeEmpty();
            expect($streamEndEvent->additionalContent)->toHaveKey('thinking_signature');
            expect($streamEndEvent->additionalContent['thinking_signature'])->not->toBeEmpty();
        });
    });
});

describe('exception handling', function (): void {
    it('throws a PrismRateLimitedException with a 429 response code', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('Who are you?')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }
    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the RateLimitException', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        Http::fake([
            '*' => Http::response(
                status: 429,
                headers: [
                    'anthropic-ratelimit-requests-limit' => 1000,
                    'anthropic-ratelimit-requests-remaining' => 500,
                    'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
                    'anthropic-ratelimit-input-tokens-limit' => 80000,
                    'anthropic-ratelimit-input-tokens-remaining' => 0,
                    'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
                    'anthropic-ratelimit-output-tokens-limit' => 16000,
                    'anthropic-ratelimit-output-tokens-remaining' => 15000,
                    'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'anthropic-ratelimit-tokens-limit' => 96000,
                    'anthropic-ratelimit-tokens-remaining' => 15000,
                    'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'retry-after' => 40,
                ]
            ),
        ])->preventStrayRequests();

        try {
            $response = Prism::text()
                ->using('anthropic', 'claude-3-5-sonnet-20240620')
                ->withPrompt('Hello world!')
                ->asStream();

            foreach ($response as $event) {
                // Don't remove me rector!
            }
        } catch (PrismRateLimitedException $e) {
            expect($e->retryAfter)->toEqual(40);
            expect($e->rateLimits)->toHaveCount(4);
            expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($e->rateLimits[0]->name)->toEqual('requests');
            expect($e->rateLimits[0]->limit)->toEqual(1000);
            expect($e->rateLimits[0]->remaining)->toEqual(500);
            expect($e->rateLimits[0]->resetsAt)->toEqual($requests_reset);

            expect($e->rateLimits[1]->name)->toEqual('input-tokens');
            expect($e->rateLimits[1]->limit)->toEqual(80000);
            expect($e->rateLimits[1]->remaining)->toEqual(0);
        }
    });

    it('throws an overloaded exception if the Anthropic responds with a 529', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 529,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }

    })->throws(PrismProviderOverloadedException::class);

    it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 413,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $event) {
            // Don't remove me rector!
        }

    })->throws(PrismRequestTooLargeException::class);
});

describe('basic stream events', function (): void {
    it('can generate text with a basic stream', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-20250219')
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
        expect($text)->not->toBeEmpty();

        // Verify the HTTP request
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['stream'] === true;
        });
    });
});

describe('step events', function (): void {
    it('emits step start and step finish events', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-20250219')
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
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

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
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withTools($tools)
            ->withMaxSteps(3)
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
});

describe('client-executed tools', function (): void {
    it('stops streaming when a client-executed tool is called', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-client-executed-tool');

        $tool = Tool::as('client_tool')
            ->for('A tool that executes on the client')
            ->withStringParameter('input', 'Input parameter')
            ->clientExecuted();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Use the client tool')
            ->asStream();

        $events = [];
        $toolCallFound = false;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof ToolCallEvent) {
                $toolCallFound = true;
            }
        }

        expect($toolCallFound)->toBeTrue();

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::ToolCalls);
    });
});

describe('approval-required tools', function (): void {
    it('stops streaming with an approval request event when approval is required', function (): void {
        FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-approval-tool');

        $tool = Tool::as('delete_file')
            ->for('Delete a file. Requires user approval.')
            ->withStringParameter('path', 'File path to delete')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Delete the file at /tmp/test.txt')
            ->asStream();

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $eventTypes = array_map(fn (StreamEvent $e): string => $e::class, $events);

        $approvalIndex = array_search(ToolApprovalRequestEvent::class, $eventTypes);
        expect($approvalIndex)->not->toBeFalse();

        $approvalEvent = $events[$approvalIndex];
        expect($approvalEvent->toolCall->name)->toBe('delete_file')
            ->and($approvalEvent->approvalId)->toStartWith('apr_');

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::ToolCalls);
    });
});
