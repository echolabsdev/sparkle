<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('sends correct basic text generation payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([
            new UserMessage('Hello, how are you?'),
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKeys(['model', 'messages']);
        expect($payload['model'])->toBe('claude-3-5-haiku-latest');
        expect($payload['messages'])->toBe([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello, how are you?',
                    ],
                ],
            ],
        ]);

        return true;
    });
});

it('sends correct system messages in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withMessages([
            new UserMessage('Hello'),
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('system');
        expect($payload['system'])->toBe([
            [
                'type' => 'text',
                'text' => 'You are a helpful assistant.',
            ],
        ]);

        return true;
    });
});

it('sends correct temperature and top_p in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->usingTemperature(0.7)
        ->usingTopP(0.9)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('temperature');
        expect($payload)->toHaveKey('top_p');
        expect($payload['temperature'])->toBe(0.7);
        expect($payload['top_p'])->toBe(0.9);

        return true;
    });
});

it('sends correct max_tokens in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->withMaxTokens(1000)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload['max_tokens'])->toBe(1000);

        return true;
    });
});

it('sends correct tools in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $tool = Tool::as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'The city name', true);

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('What is the weather?')])
        ->withTools([$tool])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('tools');
        expect($payload['tools'])->toEqual([
            [
                'name' => 'get_weather',
                'description' => 'Get current weather',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [
                        'location' => (object) [
                            'description' => 'The city name',
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ]);

        return true;
    });
});

it('sends correct tool_choice in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $tool = Tool::as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'The city name', true);

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('What is the weather?')])
        ->withTools([$tool])
        ->withToolChoice(ToolChoice::Any)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('tool_choice');
        expect($payload['tool_choice'])->toBe(['type' => 'any']);

        return true;
    });
});

it('sends correct adaptive thinking mode in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Solve this math problem: 2+2')])
        ->withProviderOptions([
            'thinking' => [
                'type' => 'adaptive',
            ],
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('thinking');
        expect($payload['thinking'])->toBe([
            'type' => 'adaptive',
        ]);

        return true;
    });
});

it('sends adaptive thinking with effort in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Solve this math problem: 2+2')])
        ->withProviderOptions([
            'thinking' => [
                'type' => 'adaptive',
            ],
            'effort' => 'high',
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload['thinking'])->toBe([
            'type' => 'adaptive',
        ]);
        expect($payload['output_config'])->toBe([
            'effort' => 'high',
        ]);

        return true;
    });
});

it('sends effort without thinking in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Quick question')])
        ->withProviderOptions([
            'effort' => 'medium',
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('thinking');
        expect($payload['output_config'])->toBe([
            'effort' => 'medium',
        ]);

        return true;
    });
});

it('does not include output_config when effort is not set', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('output_config');

        return true;
    });
});

it('sends correct legacy thinking mode in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Solve this math problem: 2+2')])
        ->withProviderOptions([
            'thinking' => [
                'enabled' => true,
                'budgetTokens' => 2048,
            ],
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('thinking');
        expect($payload['thinking'])->toBe([
            'type' => 'enabled',
            'budget_tokens' => 2048,
        ]);

        return true;
    });
});

it('omits thinking when withReasoning(false) is used even with thinking.enabled', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->withReasoning(false)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('thinking');

        return true;
    });
});

it('does not include thinking when withReasoning(false) on a non-thinking model', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->withReasoning(false)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('thinking');

        return true;
    });
});

it('sends correct legacy thinking mode with default budget tokens', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Think about this')])
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('thinking');
        expect($payload['thinking'])->toBe([
            'type' => 'enabled',
            'budget_tokens' => 1024, // default from config
        ]);

        return true;
    });
});

it('takes the default thinking budget from the documented config key', function (): void {
    // The config file and docs put it at prism.providers.anthropic, and the
    // handlers read prism.anthropic, so ANTHROPIC_DEFAULT_THINKING_BUDGET was
    // ignored and every request got 1024.
    config()->set('prism.providers.anthropic.default_thinking_budget', 3000);
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Think about this')])
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['thinking'] === [
        'type' => 'enabled',
        'budget_tokens' => 3000,
    ]);
});

it('omits null values from payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('system');
        expect($payload)->not->toHaveKey('tools');
        expect($payload)->not->toHaveKey('tool_choice');
        expect($payload)->not->toHaveKey('thinking');
        expect($payload)->not->toHaveKey('temperature');
        expect($payload)->not->toHaveKey('top_p');
        expect($payload)->not->toHaveKey('mcp_servers');
        expect($payload)->not->toHaveKey('output_config');

        return true;
    });
});

it('always includes max_tokens in payload because it is required by anthropic', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        // Anthropic API requires max_tokens - it should always be present
        expect($payload)->toHaveKey('max_tokens');
        expect($payload['max_tokens'])->toBeInt();
        expect($payload['max_tokens'])->toBeGreaterThan(0);

        return true;
    });
});

it('can send images from file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-image');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([
            new UserMessage(
                'What is this image',
                additionalContent: [
                    Image::fromLocalPath('tests/Fixtures/diamond.png'),
                ],
            ),
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $message = $request->data()['messages'][0]['content'];

        expect($message[0])->toBe([
            'type' => 'text',
            'text' => 'What is this image',
        ]);

        expect($message[1]['type'])->toBe('image');
        expect($message[1]['source']['data'])->toContain(
            base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
        );
        expect($message[1]['source']['media_type'])->toBe('image/png');

        return true;
    });
});

it('sends correct mcp_servers', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Think about this')])
        ->withProviderOptions([
            'mcp_servers' => [
                [
                    'name' => 'external-mcp',
                    'type' => 'url',
                    'url' => 'https://mcp-server.co/mcp',
                ],
            ],
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('mcp_servers');
        expect($payload['mcp_servers'])->toBe([
            [
                'name' => 'external-mcp',
                'type' => 'url',
                'url' => 'https://mcp-server.co/mcp',
            ],
        ]);

        return true;
    });
});

it('merges per-request anthropic_beta features with the configured ones', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'code-execution-2025-05-22');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withPrompt('Test')
        ->withProviderOptions(['anthropic_beta' => ['skills-2025-10-02', 'code-execution-2025-05-22']])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        expect($request->header('anthropic-beta')[0])
            ->toBe('code-execution-2025-05-22,skills-2025-10-02');

        return true;
    });
});

it('merges a per-request anthropic_beta STRING with the configured ones', function (): void {
    // The array form is covered above. This is the form callers actually
    // write, and the one a downstream consumer reported building a workaround
    // around: a single dated flag passed as a bare string, on a config that
    // already carries another beta.
    //
    // If this ever regressed to ASSIGNMENT rather than a merge, the symptom
    // would be a feature switching itself off silently -- the request still
    // succeeds, the other beta is simply gone, and nothing reports it.
    config()->set('prism.providers.anthropic.anthropic_beta', 'web-fetch-2025-09-10');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withPrompt('Test')
        ->withProviderOptions(['anthropic_beta' => 'context-management-2025-06-27'])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        expect($request->header('anthropic-beta')[0])
            ->toBe('web-fetch-2025-09-10,context-management-2025-06-27');

        return true;
    });
});

it('sends only configured beta features when the request adds none', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'code-execution-2025-05-22');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withPrompt('Test')
        ->asText();

    Http::assertSent(function (Request $request): bool {
        expect($request->header('anthropic-beta')[0])->toBe('code-execution-2025-05-22');

        return true;
    });
});
