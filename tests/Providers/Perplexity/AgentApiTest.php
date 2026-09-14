<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Perplexity\Maps\PresetMap;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('posts to the agent endpoint, not the retired sonar one', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/v1/agent'));
});

it('reads the answer out of the typed output array', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt("How's the weather in southern Brazil?")
        ->asText();

    expect($response->text)->toContain('Southern Brazil in mid-November')
        ->and($response->usage->promptTokens)->toBe(8)
        ->and($response->usage->completionTokens)->toBe(346)
        ->and($response->meta->id)->toBe('resp_5f9c1a2b3d4e5f60');
});

it('keeps sources as structured data rather than prose', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

    $results = $response->additionalContent['search_results'];

    // Resolvable sources are the whole value of this provider — a caller
    // repeating an answer has to be able to check where it came from.
    expect($results)->toHaveCount(2)
        ->and($results[0]['url'])->toBe('https://www.sunheron.com/south-america/brazil/south-brazil-weather-november/')
        ->and($results[1]['title'])->toBe('Climate of South Brazil')
        ->and($response->text)->not->toContain('http');
});

it('surfaces which model a preset actually resolved to', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-no-search-results');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('2+2?')->asText();

    // A preset can route to a third party. Token ledgers and data-handling
    // decisions both turn on knowing which vendor served the call.
    expect($response->additionalContent['resolved_model'])->toBe('google/gemini-3-pro');
});

it('preserves response identity, annotations, and the full usage ledger', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_ledger',
        'status' => 'completed',
        'model' => 'openai/gpt-5.4',
        'output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text',
            'text' => 'Grounded.',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://prismphp.com']],
        ]]]],
        'usage' => [
            'input_tokens' => 2,
            'output_tokens' => 1,
            'cost' => ['currency' => 'USD', 'total_cost' => 0.01, 'tool_calls_cost' => 0.004],
            'tool_calls_details' => ['web_search' => 1],
        ],
    ])]);

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

    expect($response->additionalContent['response_id'])->toBe('resp_ledger')
        ->and($response->additionalContent['response_status'])->toBe('completed')
        ->and($response->additionalContent['annotations'][0]['url'])->toBe('https://prismphp.com')
        ->and($response->additionalContent['usage']['cost']['tool_calls_cost'])->toBe(0.004)
        ->and($response->additionalContent['usage']['tool_calls_details']['web_search'])->toBe(1);
});

it('treats an empty source list on a completed run as normal', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-no-search-results');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('2+2?')->asText();

    expect($response->text)->toBe('Four.')
        ->and($response->additionalContent)->not->toHaveKey('search_results');
});

it('fails on a failed run even though the transport said 200', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-run-failed');

    expect(fn (): Response => Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText())
        ->toThrow(PrismException::class, 'run_failed');
});

it('fails on a cancelled run too, not only a failed one', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-run-cancelled');

    expect(fn (): Response => Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText())
        ->toThrow(PrismException::class, 'run_cancelled');
});

describe('preset translation', function (): void {
    it('sends a preset and omits model for a retired slug', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Hi')->asText();

        Http::assertSent(function ($request): bool {
            // Both fields together is not an error the API reports — it
            // silently prefers `model`, so the preset would be ignored.
            expect($request->data())->toHaveKey('preset')
                ->and($request->data()['preset'])->toBe('low')
                ->and($request->data())->not->toHaveKey('model');

            return true;
        });
    });

    it('maps deep research to medium, the behavioural equivalent', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar-deep-research')->withPrompt('Hi')->asText();

        // Perplexity's migration overview suggests `high`, but their own preset
        // rename shows the preset formerly called deep-research is now called
        // medium. `high` is the tier above — a costlier upgrade, not an
        // equivalent, and one that bills more while returning a plausible
        // answer.
        Http::assertSent(fn ($request): bool => $request->data()['preset'] === 'medium');
    });

    it('passes a real model id through as model, not a guessed preset', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'openai/gpt-5.6-sol')->withPrompt('Hi')->asText();

        Http::assertSent(function ($request): bool {
            expect($request->data())->toHaveKey('model')
                ->and($request->data()['model'])->toBe('openai/gpt-5.6-sol')
                ->and($request->data())->not->toHaveKey('preset');

            return true;
        });
    });

    it('lets an explicit preset override the translation', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar-deep-research')
            ->withProviderOptions(['preset' => 'high'])
            ->withPrompt('Hi')
            ->asText();

        Http::assertSent(fn ($request): bool => $request->data()['preset'] === 'high');
    });

    it('accepts a preset name given directly as the model', function (): void {
        expect(PresetMap::presetFor('xhigh'))->toBe('xhigh')
            ->and(PresetMap::presetFor('sonar'))->toBe('fast')
            ->and(PresetMap::presetFor('openai/gpt-5.6-sol'))->toBeNull();
    });
});

describe('input mapping', function (): void {
    it('sends a single user turn as a plain string', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Just this')->asText();

        Http::assertSent(fn ($request): bool => $request->data()['input'] === 'Just this');
    });

    it('keeps roles when there is a conversation', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withMessages([
                new UserMessage('First question'),
                new AssistantMessage('First answer'),
                new UserMessage('Follow up'),
            ])
            ->asText();

        // Flattening a conversation into one string loses which side said
        // what, and a model that cannot tell its own answers from the user's
        // questions answers worse.
        Http::assertSent(function ($request): bool {
            expect($request->data()['input'])->toBe([
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'First answer'],
                ['role' => 'user', 'content' => 'Follow up'],
            ]);

            return true;
        });
    });

    it('sends a system prompt as instructions, and only when set', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

        // Absent by default on purpose: instructions REPLACE a preset's own
        // system prompt, so sending an empty one would throw away half of what
        // the preset is.
        Http::assertSent(fn ($request): bool => ! array_key_exists('instructions', $request->data()));
    });

    it('passes a system prompt through when the caller sets one', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withSystemPrompt('Answer only in French.')
            ->withPrompt('Hi')
            ->asText();

        Http::assertSent(fn ($request): bool => $request->data()['instructions'] === 'Answer only in French.');
    });
});

describe('tools', function (): void {
    it('refuses Prism tools rather than dropping them on the floor', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        // Found by dogfooding against a live key: the provider accepted
        // withTools() and quietly ignored it, coming back with zero steps and
        // zero tool calls. That reads as the model declining to call the tool,
        // which is worse than an error.
        expect(fn (): Response => Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withTools([(new Tool)->as('noop')->for('does nothing')->using(fn (): string => 'ok')])
            ->withPrompt('Hi')
            ->asText())
            ->toThrow(PrismException::class, 'cannot execute Prism tools');
    });

    it('passes Perplexity own server-side tools through', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withProviderOptions(['tools' => [['type' => 'web_search']]])
            ->withPrompt('Hi')
            ->asText();

        Http::assertSent(fn ($request): bool => $request->data()['tools'] === [['type' => 'web_search']]);
    });

    it('keeps nested web filters intact', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        $tools = [['type' => 'web_search', 'filters' => [
            'search_domain_filter' => ['prismphp.com'],
            'search_after_date_filter' => '01/01/2026',
        ]]];

        Prism::text()->using(Provider::Perplexity, 'sonar')
            ->withProviderOptions(['tools' => $tools])->withPrompt('Hi')->asText();

        Http::assertSent(fn ($request): bool => $request->data()['tools'] === $tools);
    });
});

it('passes current Agent API controls without flattening them', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions([
        'max_steps' => 9,
        'models' => ['openai/gpt-5.4', 'anthropic/claude-sonnet-4-6'],
        'previous_response_id' => 'resp_prior',
        'store' => true,
        'reasoning' => ['effort' => 'high'],
        'skills' => [['type' => 'browser']],
        'metadata' => ['run_id' => 'run_1'],
    ])->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data())->toMatchArray([
            'max_steps' => 9,
            'models' => ['openai/gpt-5.4', 'anthropic/claude-sonnet-4-6'],
            'previous_response_id' => 'resp_prior',
            'store' => true,
            'reasoning' => ['effort' => 'high'],
            'skills' => [['type' => 'browser']],
            'metadata' => ['run_id' => 'run_1'],
        ]);

        return true;
    });
});

describe('reported cost', function (): void {
    it('reports the cost Perplexity priced the request at', function (): void {
        // Perplexity is one of only two providers that price a request in
        // their own response. Dropping it makes an application derive an
        // estimate when it could have had the real figure.
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

        expect($response->usage->cost)->toBe(0.005);
    });

    it('reports a genuine zero rather than nothing', function (): void {
        // A cached or free-tier request costs nothing, and that is an answer.
        // Reporting null would send the caller off to estimate a figure the
        // provider already gave them.
        Http::fake(['api.perplexity.ai/*' => Http::response([
            'status' => 'completed',
            'model' => 'openai/gpt-5.1',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'cost' => ['total_cost' => 0]],
        ])]);

        $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

        expect($response->usage->cost)->toBe(0.0);
    });

    it('leaves cost null when the response carries none', function (): void {
        Http::fake(['api.perplexity.ai/*' => Http::response([
            'status' => 'completed',
            'model' => 'openai/gpt-5.1',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

        expect($response->usage->cost)->toBeNull();
    });
});
