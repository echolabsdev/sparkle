<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ProviderTool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(11);
    expect($response->usage->completionTokens)->toBe(55);
    expect($response->usage->cacheWriteInputTokens)->toBeNull();
    expect($response->usage->cacheReadInputTokens)->toBeNull();
    expect($response->meta->id)->toBe('msg_01X2Qk7LtNEh4HB9xpYU57XU');
    expect($response->meta->model)->toBe('claude-3-5-sonnet-20240620');
    expect($response->text)->toBe(
        "I am an AI assistant created by Anthropic to be helpful, harmless, and honest. I don't have a physical form or avatar - I'm a language model trained to engage in conversation and help with tasks. How can I assist you today?"
    );
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(33);
    expect($response->usage->completionTokens)->toBe(98);
    expect($response->meta->id)->toBe('msg_016EjDAMDeSvG229ZjspjC7J');
    expect($response->meta->model)->toBe('claude-3-5-sonnet-20240620');
    expect($response->text)->toBe(
        'I am Nyx, an ancient and unfathomable entity from the depths of cosmic darkness. My form is beyond mortal comprehension - a writhing mass of tentacles and eyes that would shatter the sanity of those who gaze upon me. I exist beyond the boundaries of time and space as you know them. My knowledge spans eons and transcends human understanding. What brings you to seek audience with one such as I, tiny mortal?'
    );
});

describe('tools', function (): void {
    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-multiple-tools');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'the city you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asText();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('search');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'query' => 'Detroit Tigers baseball game time today',
        ]);

        // Assert tool calls in the second step
        $secondStep = $response->steps[1];
        expect($secondStep->toolCalls)->toHaveCount(1);
        expect($secondStep->toolCalls[0]->name)->toBe('weather');
        expect($secondStep->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        // Verify the assistant message from step 1 is present in step 2's input messages
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(1);
        expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('search');
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        // Verify the assistant message from step 2 is present in step 3's input messages
        $thirdStep = $response->steps[2];
        expect($thirdStep->messages)->toHaveCount(5);
        expect($thirdStep->messages[3])->toBeInstanceOf(AssistantMessage::class);
        expect($thirdStep->messages[3]->toolCalls)->toHaveCount(1);
        expect($thirdStep->messages[3]->toolCalls[0]->name)->toBe('weather');
        expect($thirdStep->messages[4])->toBeInstanceOf(ToolResultMessage::class);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(1650);
        expect($response->usage->completionTokens)->toBe(307);

        // Assert response
        expect($response->meta->id)->toBe('msg_011fBqNVVh5AwC3uyiq78qrj');
        expect($response->meta->model)->toBe('claude-3-5-sonnet-20240620');

        // Assert final text content
        expect($response->text)->toContain('The Tigers game is scheduled for 3:00 PM today in Detroit');
        expect($response->text)->toContain('it will be 75°F (about 24°C) and sunny');
        expect($response->text)->toContain("you likely won't need a coat");
    });

    it('it handles a provider tool', function (): void {
        config()->set('prism.providers.anthropic.anthropic_beta', 'code-execution-2025-05-22');

        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-provider-tool');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-haiku-latest')
            ->withPrompt('Solve the equation 3x + 10 = 14.')
            ->withProviderTools([new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')])
            ->asText();

        expect($response->text)->toContain('4/3');

        $finalStep = $response->steps->last();
        expect($finalStep->providerToolCalls)->not->toBeEmpty();
        expect($finalStep->providerToolCalls[0]->type)->toBe('code_execution');
        expect($finalStep->providerToolCalls[0]->status)->toBe('completed');
    });

    it('handles a provider tool with a user defined tool', function (): void {
        config()->set('prism.providers.anthropic.anthropic_beta', 'code-execution-2025-05-22');

        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-provider-tool-and-user-tool');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-haiku-latest')
            ->withPrompt('If the current temperature in Detroit is X, what is Y in the following equation: 3x + 10 = Y?')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')])
            ->withMaxSteps(3)
            ->asText();

        expect($response->text)->toContain('235');
        expect($response->steps)->toHaveCount(2);

        // Verify the assistant message from step 1 is present in step 2's input messages
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3)
            ->and($secondStep->messages[0])->toBeInstanceOf(UserMessage::class)
            ->and($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class)
            ->and($secondStep->messages[1]->toolCalls)->toHaveCount(1)
            ->and($secondStep->messages[1]->toolCalls[0]->name)->toBe('weather')
            ->and($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-required-tool-call');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice('weather')
            ->asText();

        expect($response->toolCalls[0]->name)->toBe('weather');
    });

    it('preserves server tool content blocks during multi-step tool loop with web search', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-web-search-and-tool-call');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 21° and partly cloudy'),
        ];

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-haiku-latest')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in London?')
            ->asText();

        // The tool loop should complete without a "Could not find search result for citation index" error
        expect($response->steps)->toHaveCount(2);
        expect($response->text)->toContain('18°C');

        // Step 1 should have the tool call and citations in additionalContent
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('weather');
        expect($firstStep->additionalContent)->toHaveKey('citations');
        expect($firstStep->additionalContent)->toHaveKey('provider_tool_calls');
        expect($firstStep->additionalContent)->toHaveKey('provider_tool_results');

        // The assistant message replayed in step 2 should contain the server tool blocks
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->additionalContent)->toHaveKey('citations');
        expect($secondStep->messages[1]->additionalContent)->toHaveKey('provider_tool_calls');
        expect($secondStep->messages[1]->additionalContent)->toHaveKey('provider_tool_results');

        // Verify the second HTTP request includes server tool blocks in the replayed assistant message
        $requests = Http::recorded();
        expect($requests)->toHaveCount(2);

        $secondRequestPayload = $requests[1][0]->data();
        $assistantMessage = Arr::first(
            $secondRequestPayload['messages'],
            fn (array $msg): bool => $msg['role'] === 'assistant'
        );

        // The assistant message should contain server_tool_use and web_search_tool_result blocks
        $contentTypes = array_column($assistantMessage['content'], 'type');
        expect($contentTypes)->toContain('server_tool_use');
        expect($contentTypes)->toContain('web_search_tool_result');
        expect($contentTypes)->toContain('text');
        expect($contentTypes)->toContain('tool_use');
    });
});

it('can calculate cache usage correctly', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/calculate-cache-usage');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withMessages([
            (new UserMessage('New context'))->withProviderOptions(['cacheType' => 'ephemeral']),
        ])
        ->asText();

    expect($response->usage->cacheWriteInputTokens)->toBe(200);
    expect($response->usage->cacheReadInputTokens)->ToBe(100);
});

it('reports the thinking tokens Anthropic actually sends', function (): void {
    // Reported by a consumer against the live API on claude-opus-5: Anthropic
    // puts reasoning at `usage.output_tokens_details.thinking_tokens`, and every
    // Anthropic handler here built Usage without it. So thoughtTokens was null
    // on every call, and a turn that reasoned hard was indistinguishable from
    // one that did not think at all -- which is the question adaptive thinking
    // makes worth asking, since the model now decides per request.
    //
    // The numbers are the ones measured in that report.
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-thinking');

    $response = Prism::text()
        ->using('anthropic', 'claude-opus-5')
        ->withPrompt('Think about this.')
        ->asText();

    expect($response->usage->thoughtTokens)->toBe(1240);

    // And a BREAKDOWN of output, not an addition to it. A consumer pricing
    // `completionTokens + thoughtTokens` double-counts the expensive half, so
    // the relationship is asserted rather than left to the docblock.
    expect($response->usage->completionTokens)->toBe(2820);
    expect($response->usage->thoughtTokens)->toBeLessThan($response->usage->completionTokens);
});

it('leaves thoughtTokens null when Anthropic reports no thinking', function (): void {
    // The control. Without it the assertion above passes on an implementation
    // that hardcodes 1240, and null must keep meaning "this turn did not think"
    // rather than "nobody read the field".
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->thoughtTokens)->toBeNull();
});

it('adds rate limit data to the responseMeta', function (): void {
    $requests_reset = Carbon::now()->addSeconds(30);

    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-a-prompt',
        [
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
        ]
    );

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->meta->rateLimits)->toHaveCount(4);
    expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

it('does not destroy a paid-for response when a reset header cannot be read', function (): void {
    // `new Carbon('soon')` raises InvalidFormatException, and rate limits are
    // parsed on the SUCCESS path -- after the model has answered and the call
    // has been billed. Before this was guarded, an unreadable reset header threw
    // straight through `asText()`, so a quota HINT destroyed the response it was
    // attached to.
    //
    // The header is not necessarily the provider's, either: whatever proxy or
    // gateway sits in front of the API can set it, so a 200 does not make the
    // value trusted input.
    //
    // '1m30s' is not a contrived string. It is the compound duration OpenAI
    // really sends, and nothing stops it appearing here.
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-a-prompt',
        [
            'anthropic-ratelimit-requests-limit' => 1000,
            'anthropic-ratelimit-requests-remaining' => 500,
            'anthropic-ratelimit-requests-reset' => 'soon',
            'anthropic-ratelimit-tokens-limit' => 96000,
            'anthropic-ratelimit-tokens-remaining' => 15000,
            'anthropic-ratelimit-tokens-reset' => '1m30s',
        ]
    );

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    // The response survives, which is the whole point.
    expect($response->text)->not->toBeEmpty();

    // And the quota it COULD read is still reported. Failing to null loses the
    // reset instant only -- the same value a provider sending no reset header
    // at all would give -- rather than losing the whole bucket.
    expect($response->meta->rateLimits)->toHaveCount(2);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toBeNull();
    expect($response->meta->rateLimits[1]->resetsAt)->toBeNull();
});

it('handles unix timestamp rate limit reset headers', function (): void {
    // Unix timestamps have second precision, so we truncate microseconds for comparison.
    $requests_reset = Carbon::now()->addSeconds(30)->startOfSecond();

    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-a-prompt',
        [
            'anthropic-ratelimit-requests-limit' => 1000,
            'anthropic-ratelimit-requests-remaining' => 500,
            'anthropic-ratelimit-requests-reset' => (string) $requests_reset->timestamp,
        ]
    );

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->meta->rateLimits)->toHaveCount(1);
    expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

it('reads the rate limits a title-casing proxy handed back', function (): void {
    // HTTP field names are case-insensitive (RFC 9110 5.1) and a gateway that
    // title-cases them is ordinary rather than hostile. The prefix used to be
    // matched against the raw wire case, so this response reported NO rate
    // limits at all -- and an empty list is also what a response that
    // legitimately carried none looks like, which makes the failure invisible
    // at the moment it happens and permanent afterwards.
    $requests_reset = Carbon::now()->addSeconds(30);

    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-a-prompt',
        [
            'Anthropic-RateLimit-Requests-Limit' => 1000,
            'Anthropic-RateLimit-Requests-Remaining' => 500,
            'Anthropic-RateLimit-Requests-Reset' => $requests_reset->toISOString(),
        ]
    );

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->meta->rateLimits)->toHaveCount(1);
    // The BUCKET NAME is folded too, so a caller matching on 'requests' finds it
    // whatever case the proxy chose.
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

describe('Anthropic context management', function (): void {
    it('sends context_management through to the API', function (): void {
        // The beta HEADER was already reachable via providerOptions, but the
        // request body is an allowlist -- so a caller could switch the beta on
        // and the field silently never travelled. A knob that looks reachable
        // and is not. Reported as #35 with the gap measured, and the shape below
        // verified against the live API (HTTP 200) rather than read off docs.
        //
        // Deliberately a RAW PASSTHROUGH. The edits carry dated identifiers --
        // clear_tool_uses_20250919, clear_thinking_20251015, compact_20260112 --
        // so a typed builder would freeze a shape that is going to move. One
        // line carries all three and whatever replaces them.
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

        $edits = [
            'edits' => [[
                'type' => 'clear_tool_uses_20250919',
                'trigger' => ['type' => 'input_tokens', 'value' => 30000],
            ]],
        ];

        Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Who are you?')
            ->withProviderOptions(['context_management' => $edits])
            ->asText();

        Http::assertSent(function (Request $request) use ($edits): bool {
            expect($request->data())->toHaveKey('context_management');
            expect($request->data()['context_management'])->toBe($edits);

            return true;
        });
    });

    it('omits context_management when the caller asks for none', function (): void {
        // The control. Without it the test above passes against a handler that
        // sends the key unconditionally, which would put a null into every
        // Anthropic request ever made.
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Who are you?')
            ->asText();

        Http::assertSent(function (Request $request): bool {
            expect($request->data())->not->toHaveKey('context_management');

            return true;
        });
    });

    it('surfaces what the server actually cleared', function (): void {
        // The half that carries the weight. Sending the request without
        // surfacing the answer is a feature you cannot verify: "cleared 40 tool
        // results" and "the beta header was ignored" look identical from
        // outside -- a successful response and a smaller bill nobody can
        // attribute.
        //
        // additionalContent is built from named extractors behind
        // Arr::whereNotNull, so a top-level response key had nowhere to land.
        // The block below is the real shape, confirmed against the live API.
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-context-management');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->additionalContent)->toHaveKey('context_management');
        expect($response->additionalContent['context_management']['applied_edits'][0]['type'])
            ->toBe('clear_tool_uses_20250919');
        expect($response->additionalContent['context_management']['applied_edits'][0]['cleared_tool_uses'])
            ->toBe(12);
    });

    it('has no context_management key when the server applied none', function (): void {
        // The control, and it is the one that matters here: an empty
        // applied_edits is a REAL answer meaning "the edit ran and cleared
        // nothing", while an absent block means the request never asked. If the
        // key appeared unconditionally those two would be indistinguishable,
        // which is the exact confusion this change exists to remove.
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->additionalContent)->not->toHaveKey('context_management');
    });
});

describe('Anthropic citations', function (): void {
    it('applies the citations request level providerOptions to all documents', function (): void {
        Prism::fake();

        $request = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true]);

        $payload = Text::buildHttpRequestPayload($request->toRequest());

        expect($payload['messages'])->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'What color is the grass and sky?',
                ],
                [
                    'type' => 'document',
                    'citations' => ['enabled' => true],
                    'source' => [
                        'type' => 'text',
                        'media_type' => 'text/plain',
                        'data' => 'The grass is green. The sky is blue.',
                    ],
                ],
            ],
        ]]);
    });

    it('does not invent a citations entry when the response cites nothing', function (): void {
        // The control the citation tests never had. Every other test in this
        // group sends a response that HAS citations, so all of them passed
        // against a guard that added a citations entry to literally every
        // Anthropic response -- one MessagePartWithCitations per content block,
        // each holding an empty citation list and a copy of the output text.
        //
        // The cause was `data_get($data, 'content.*.citations', []) === []`:
        // a wildcard yields one entry PER BLOCK, null where the key is absent,
        // so an ordinary answer produced [null] and never []. A caller could
        // not tell "this answer cites sources" from "this answer exists".
        //
        // Found by the prism-parity anthropic-text-response suite on its first
        // run, where prism-ts and prism-py both disagreed with this package and
        // agreed with each other.
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->additionalContent)->not->toHaveKey('citations');
        expect($response->steps->first()->additionalContent)->not->toHaveKey('citations');
    });

    it('adds citations to additionalContent on response steps and assistant message for PDF documents', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-pdf-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromLocalPath('tests/Fixtures/test-pdf.pdf'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->toEqual('According to the text, the grass is green and the sky is blue.');

        expect($response->additionalContent['citations'])->toHaveCount(5);
        expect($response->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['citations'][1];

        expect($messagePart->outputText)->toBe('the grass is green');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->sourceType)->toBe(CitationSourceType::Document);
        expect($messagePart->citations[0]->sourceText)->toBe('The grass is green. ');
        expect($messagePart->citations[0]->sourceStartIndex)->toBe(1);
        expect($messagePart->citations[0]->sourceEndIndex)->toBe(2);
        expect($messagePart->citations[0]->source)->toBe(0);
        expect($messagePart->citations[0]->sourceTitle)->toBe('All aboout the grass and the sky');
        expect($messagePart->citations[0]->sourcePositionType)->toBe(CitationSourcePositionType::Page);

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(5);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(5);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('adds citations to additionalContent on response steps and assistant message for text documents', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-text-document-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->toBe("According to the documents:\nThe grass is green and the sky is blue.");

        expect($response->additionalContent['citations'])->toHaveCount(5);
        expect($response->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['citations'][1];

        expect($messagePart->outputText)->toBe('The grass is green');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->sourceType)->toBe(CitationSourceType::Document);
        expect($messagePart->citations[0]->sourceText)->toBe('The grass is green. ');
        expect($messagePart->citations[0]->sourceStartIndex)->toBe(0);
        expect($messagePart->citations[0]->sourceEndIndex)->toBe(20);
        expect($messagePart->citations[0]->source)->toBe(0);
        expect($messagePart->citations[0]->sourceTitle)->toBe('All aboout the grass and the sky');
        expect($messagePart->citations[0]->sourcePositionType)->toBe(CitationSourcePositionType::Character);

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(5);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(5);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('adds citations to additionalContent on response steps and assistant message for custom content documents', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-custom-content-document-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromChunks(['The grass is green.', 'The sky is blue.']),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->toBe('According to the documents, the grass is green and the sky is blue.');

        expect($response->additionalContent['citations'])->toHaveCount(5);
        expect($response->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['citations'][1];

        expect($messagePart->outputText)->toBe('the grass is green');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->sourceType)->toBe(CitationSourceType::Document);
        expect($messagePart->citations[0]->sourceText)->toBe('The grass is green.');
        expect($messagePart->citations[0]->sourceStartIndex)->toBe(0);
        expect($messagePart->citations[0]->sourceEndIndex)->toBe(1);
        expect($messagePart->citations[0]->source)->toBe(0);
        expect($messagePart->citations[0]->sourceTitle)->toBeNull();
        expect($messagePart->citations[0]->sourcePositionType)->toBe(CitationSourcePositionType::Chunk);

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(5);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(5);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('adds citations to additionalContent on response steps and assistant message for the web search tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
            ->withPrompt('What is the weather going to be like in London today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
            ->asText();

        $citationChunk = Arr::first(
            $response->additionalContent['citations'],
            fn (MessagePartWithCitations $part): bool => $part->citations !== [] && $part->citations[0]->sourceType === CitationSourceType::Url
        );

        expect($citationChunk->outputText)->toContain('temperatures');
        expect($citationChunk->citations)->toHaveCount(1);
        expect($citationChunk->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($citationChunk->citations[0]->sourceText)->toContain('temperatures');
        expect($citationChunk->citations[0]->sourceTitle)->toContain('Weather');
        expect($citationChunk->citations[0]->source)->toBe('https://www.easeweather.com/europe/united-kingdom/england/greater-london/london/july');

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(13);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(13);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('can handle citations on on a previous assistant message with a document', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-text-document-citations-on-followup');

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.', 'All aboout the grass and the sky'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        $responseTwo = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                ...$response->steps->last()->messages,
                new UserMessage('Is the source you have cited reliable?'),
            ])
            ->asText();

        expect($responseTwo->text)->toContain('spelling error');
    });
});

describe('Anthropic thinking', function (): void {
    describe('adaptive', function (): void {
        it('can use adaptive thinking', function (): void {
            FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking');

            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
                ->asText();

            expect($response->additionalContent)->toHaveKey('thinking');
            expect($response->additionalContent['thinking'])->toContain('Douglas Adams');
            expect($response->additionalContent)->toHaveKey('thinking_signature');
            expect($response->additionalContent['thinking_signature'])->not->toBeEmpty();
        });

        it('can use adaptive thinking with tool calls', function (): void {
            FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking-and-tool-calls');

            $tools = [
                Tool::as('weather')
                    ->for('useful when you need to search for current weather conditions')
                    ->withStringParameter('city', 'the city you want the weather for')
                    ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
                Tool::as('search')
                    ->for('useful for searching curret events or data')
                    ->withStringParameter('query', 'The detailed search query')
                    ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
            ];

            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withTools($tools)
                ->withMaxSteps(3)
                ->withPrompt('What time is the tigers game today and should I wear a coat?')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
                ->asText();

            expect($response->steps->first())
                ->additionalContent->thinking->toContain('Tigers')
                ->additionalContent->thinking_signature->not->toBeEmpty();

            expect($response->steps->last()->messages[1])
                ->additionalContent->thinking->toContain('Tigers')
                ->additionalContent->thinking_signature->not->toBeEmpty();
        });

        it('sends adaptive thinking payload', function (): void {
            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('Test')
                ->withProviderOptions(['thinking' => ['type' => 'adaptive']]);

            $payload = Text::buildHttpRequestPayload($response->toRequest());

            expect(data_get($payload, 'thinking'))->toBe(['type' => 'adaptive']);
        });

        it('sends effort via output_config', function (): void {
            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('Test')
                ->withProviderOptions(['effort' => 'medium']);

            $payload = Text::buildHttpRequestPayload($response->toRequest());

            expect(data_get($payload, 'output_config.effort'))->toBe('medium');
        });
    });

    describe('legacy', function (): void {
        it('can use extended thinking', function (): void {
            FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking');

            $response = Prism::text()
                ->using('anthropic', 'claude-3-7-sonnet-latest')
                ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
                ->withProviderOptions(['thinking' => ['enabled' => true]])
                ->asText();

            $expected_thinking = "This is a reference to Douglas Adams' popular science fiction series \"The Hitchhiker's Guide to the Galaxy\" where the supercomputer Deep Thought was built to calculate \"the Answer to the Ultimate Question of Life, the Universe, and Everything.\" After 7.5 million years of computation, it famously determined the answer to be \"42\" - a deliberately anticlimactic and absurd response that has become a significant pop culture reference.\n\nBeyond the Hitchhiker's reference, the question of life's meaning appears in many works of fiction across different media, with various philosophical approaches.\n\nI should note this humorous 42 reference while also mentioning how other fictional works have approached this philosophical question.";
            $expected_signature = 'EuYBCkQYAiJAQ7ZOmBu5pa8U03x/RN5+Gs3tyKXFYcruUfnC8X/4AKBpJmB8qX+nQQ9atvYOXLD/mUAClCRZEaxt2fyEvdxnhRIMfFi6CLULECysli0mGgy5JRaOXL06fVJndm8iMD2T+D8dSIFJuctCnVeFKZme2TfIPIH+UMFO33a0ojzUq2VYy8+RzKkH7WYK9+580ipQ4yDVegd/67LKRtfb574HOHqwlPcfEbeiJuFuHrayoqK8KS2ltGYRckVGH6lNH46zUyjGaD2z3nZeti8UjmgnfMWRpjUmv0TWWGtrCKRoHGQ=';

            expect($response->text)->toBe("In popular fiction, the most famous answer to this question comes from Douglas Adams' \"The Hitchhiker's Guide to the Galaxy,\" where a supercomputer named Deep Thought calculates for 7.5 million years and determines that the answer is simply \"42.\" This deliberately absurd response has become an iconic joke about the futility of seeking simple answers to profound existential questions.\n\nBeyond this humorous reference, fiction explores life's meaning in countless ways:\n- Finding purpose through love and human connection (seen in works like \"The Good Place\")\n- The pursuit of knowledge and understanding (as in \"Contact\" by Carl Sagan)\n- Creating your own meaning in an indifferent universe (explored in existentialist fiction)\n- Religious or spiritual fulfillment (depicted in works like \"Life of Pi\")\n\nWhat makes this question compelling in fiction is that there's never a definitive answer - just different perspectives that reflect our own search for meaning.");
            expect($response->additionalContent['thinking'])->toBe($expected_thinking);
            expect($response->additionalContent['thinking_signature'])->toBe($expected_signature);

            expect($response->messages->last())
                ->additionalContent->thinking->toBe($expected_thinking)
                ->additionalContent->thinking_signature->toBe($expected_signature);
        });

        it('can override budget tokens', function (): void {
            $response = Prism::text()
                ->using('anthropic', 'claude-3-7-sonnet-latest')
                ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
                ->withProviderOptions([
                    'thinking' => [
                        'enabled' => true,
                        'budgetTokens' => 2048,
                    ],
                ]);

            $payload = Text::buildHttpRequestPayload($response->toRequest());

            expect(data_get($payload, 'thinking.budget_tokens'))->toBe(2048);
        });

        it('can use extended thinking with tool calls', function (): void {
            FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking-and-tool-calls');

            $tools = [
                Tool::as('weather')
                    ->for('useful when you need to search for current weather conditions')
                    ->withStringParameter('city', 'the city you want the weather for')
                    ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
                Tool::as('search')
                    ->for('useful for searching curret events or data')
                    ->withStringParameter('query', 'The detailed search query')
                    ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
            ];

            $response = Prism::text()
                ->using('anthropic', 'claude-3-7-sonnet-latest')
                ->withTools($tools)
                ->withMaxSteps(3)
                ->withPrompt('What time is the tigers game today and should I wear a coat?')
                ->withProviderOptions(['thinking' => ['enabled' => true]])
                ->asText();

            $expected_thinking = "The user is asking about:\n1. The time of the Tigers game today (likely referring to a sports team, probably Detroit Tigers baseball)\n2. Whether they should wear a coat (which relates to weather conditions)\n\nFor the first question, I need to search for the Tigers game schedule for today. For the second question, I need to check the weather in the relevant location.\n\nHowever, I'm missing some information:\n- The user hasn't specified which Tigers team they're referring to (though Detroit Tigers is most likely)\n- The user hasn't specified their location, which I need for the weather check\n\nI'll need to search for the Tigers game information first, and then check the weather in the appropriate location (likely Detroit if it's a home game).";
            $expected_signature = 'EuYBCkQYAiJAY1corUurDaKsURSV32GUvrp4ZySJDYJXGHIBx2aPaphiKr+Kcenv2gTcLxAvkU5zUxek2mX3GGkrp8XlN2qJAhIM7v4WGU9Wwfpn8qu1Ggzd9cK0sZX2z6qEbaciMKAfMsaYMc9zVHF1Y2qY+iC35WGiXAnEAZk+KBNGCo0V+t/U1bzJGhAigvTRKkDKpipQDXkfw+XdPzHh+VGFXut2TIPatMN5UrE1CvR+GtQT1cscbxBnuiXFwgs3B/QPlC2/l2VloajCHeYVaHqY3MIXiTyqe4HAyt51Go1Xt1ydVaY=';

            expect($response->text)->toBe("The Detroit Tigers game is today at 3pm in Detroit. The weather in Detroit will be 75° and sunny, so you likely won't need a coat. It's a warm, pleasant day - just a light jacket or sweater might be enough if you tend to get cold at outdoor events, but generally, these are comfortable conditions.");

            expect($response->steps->first())
                ->additionalContent->thinking->toBe($expected_thinking)
                ->additionalContent->thinking_signature->toBe($expected_signature);

            // Verify the assistant message with thinking is present in the second step's input messages
            expect($response->steps->last()->messages[1])
                ->additionalContent->thinking->toBe($expected_thinking)
                ->additionalContent->thinking_signature->toBe($expected_signature);
        });
    });

    describe('shapes Prism does not spell itself', function (): void {
        // These were dropped without an error: the request went out with no
        // thinking, and nothing said so. They are now sent as given, as the
        // TypeScript and Python ports send them.
        it('sends the thinking option it is given', function (mixed $thinking, mixed $expected): void {
            $request = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('Test')
                ->withProviderOptions(['thinking' => $thinking])
                ->toRequest();

            expect(Text::buildHttpRequestPayload($request)['thinking'] ?? null)->toBe($expected);
        })->with([
            "Anthropic's enabled shape" => [['type' => 'enabled', 'budget_tokens' => 2048], ['type' => 'enabled', 'budget_tokens' => 2048]],
            'adaptive with display' => [['type' => 'adaptive', 'display' => 'summarized'], ['type' => 'adaptive', 'display' => 'summarized']],
            'disabled' => [['type' => 'disabled'], ['type' => 'disabled']],
            "Prism's enabled shape is still translated" => [['enabled' => true, 'budgetTokens' => 2048], ['type' => 'enabled', 'budget_tokens' => 2048]],
            "Prism's enabled shape without an integer budget" => [['enabled' => true, 'budgetTokens' => '4000'], ['type' => 'enabled', 'budget_tokens' => 1024]],
            "Prism's spelling for off" => [['enabled' => false], null],
            'false' => [false, null],
            'an empty map' => [[], null],
        ]);

        it('lets withReasoning(false) win over any thinking shape', function (): void {
            $request = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('Test')
                ->withProviderOptions(['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]])
                ->withReasoning(false)
                ->toRequest();

            expect(Text::buildHttpRequestPayload($request))->not->toHaveKey('thinking');
        });

        it('keeps the thinking and signature Anthropic returns for a shape Prism does not spell', function (): void {
            // Extraction used to be gated on Prism's own spellings, so the block
            // came back and its signature was thrown away, and the next tool-use
            // turn could not send it.
            FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking');

            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-6')
                ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
                ->withProviderOptions(['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]])
                ->asText();

            expect($response->additionalContent['thinking'] ?? null)->toContain('Douglas Adams')
                ->and($response->additionalContent['thinking_signature'] ?? null)->not->toBeEmpty();
        });
    });
});

describe('exceptions', function (): void {
    it('throws a RateLimitException if the Anthropic responds with a 429', function (): void {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the RateLimitException', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
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
            Prism::text()
                ->using('anthropic', 'claude-3-5-sonnet-20240620')
                ->withPrompt('Hello world!')
                ->asText();
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
            'https://api.anthropic.com/*' => Http::response(
                status: 529,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismProviderOverloadedException::class);

    it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 413,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismRequestTooLargeException::class);

    it('resolves gracefully with a Refusal finish reason when Anthropic refuses', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-refusal');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Tell me something forbidden.')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Refusal);
    });
});

describe('pause_turn', function (): void {
    it('resumes the turn when Anthropic returns stop_reason="pause_turn"', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-pause-turn');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Look something up for me.')
            ->withMaxSteps(5)
            ->asText();

        // Two HTTP round-trips: the paused response, then the resumed completion.
        expect($response->steps)->toHaveCount(2);
        expect($response->steps->first()->finishReason)->toBe(FinishReason::Pause);
        expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
        expect($response->text)->toContain('the answer is 42');
    });

    it('stops resuming once maxSteps is reached', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-pause-turn');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Look something up for me.')
            ->withMaxSteps(1)
            ->asText();

        expect($response->steps)->toHaveCount(1);
        expect($response->steps->first()->finishReason)->toBe(FinishReason::Pause);
    });
});

it('allows automatic caching enabled via providerOptions', function (): void {
    Prism::fake();

    $request = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $payload = Text::buildHttpRequestPayload($request->toRequest());

    expect($payload['cache_control'])->toBe(['type' => 'ephemeral']);
});
