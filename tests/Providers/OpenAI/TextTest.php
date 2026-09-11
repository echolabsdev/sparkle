<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->meta->id)->toContain('resp_');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)->toBeString();
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-system-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->meta->id)->toContain('resp_');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)
        ->toBeString()
        ->toContain('Nyx');
});

it('sends the organization header when set', function (): void {
    config()->set('prism.providers.openai.organization', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Organization')[0] === 'echolabs');
});

it('does not send the organization header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.organization');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Organization')));
});

it('sends the api key header when set', function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-1234');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer sk-1234');
});

it('does not send the api key header', function (): void {
    config()->offsetUnset('prism.providers.openai.api_key');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();
    Http::assertSent(fn (Request $request): bool => empty($request->header('Authorization')));
});

it('sends the project header when set', function (): void {
    config()->set('prism.providers.openai.project', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Project')[0] === 'echolabs');
});

it('does not send the project header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.project');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Project')));
});

describe('tools', function (): void {
    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/responses',
            'openai/generate-text-with-multiple-tools',
        );

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('openai', 'gpt-4o')
            ->withTools($tools)
            ->usingTemperature(0)
            ->withMaxSteps(3)
            ->withSystemPrompt('Current Date: '.now()->toDateString())
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asText();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);
        expect($firstStep->toolCalls[0]->name)->toBe('search');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'query' => 'Detroit Tigers game March 14 2025 time',
        ]);

        expect($firstStep->toolCalls[1]->name)->toBe('weather');
        expect($firstStep->toolCalls[1]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        // Verify the assistant message from step 1 is present in step 2's input messages
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(2);
        expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('search');
        expect($secondStep->messages[1]->toolCalls[1]->name)->toBe('weather');
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        expect($response->usage->promptTokens)->toBeNumeric();
        expect($response->usage->completionTokens)->toBeNumeric();

        // Assert response
        expect($response->meta->id)->toContain('resp_');
        expect($response->meta->model)->toContain('gpt-4o');

        // Assert final text content
        expect($response->text)->toBe(
            "The Detroit Tigers game is today at 3 PM in Detroit. The weather in Detroit will be 75°F and sunny, so you won't need a coat!"
        );
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call');

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
            ->using('openai', 'gpt-4o')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice('weather')
            ->asText();

        expect($response->toolCalls[0]->name)->toBe('weather');
    });

    it('handles tool choice when null', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-null-tool-call');

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

        Prism::text()
            ->using('openai', 'gpt-4o')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->asText();
    })->throwsNoExceptions();

    it('it handles a provider tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-code-interpreter');

        $response = Prism::text()
            ->using('openai', 'gpt-4.1')
            ->withPrompt('Solve the equation 3x + 10 = 14.')
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->asText();

        expect($response->text)->toContain('frac{4}{3}');
    });

    it('handles a provider tool with a user defined tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call-and-provider-tool');

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
            ->using('openai', 'gpt-4.1')
            ->withPrompt('If the current temperature in Detroit is X, what is Y in the following equation: 3x + 10 = Y?')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->withMaxSteps(3)
            ->asText();

        expect($response->text)->toContain('235');
    });

    it('passes parallel tool calls setting', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/responses',
            'openai/generate-text-with-multiple-tools',
        );

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('openai', 'gpt-4o')
            ->withTools($tools)
            ->usingTemperature(0)
            ->withMaxSteps(3)
            ->withProviderOptions(['parallel_tool_calls' => false])
            ->withSystemPrompt('Current Date: '.now()->toDateString())
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asText();

        expect($response->text)->toBe(
            "The Detroit Tigers game is today at 3 PM in Detroit. The weather in Detroit will be 75°F and sunny, so you won't need a coat!"
        );

        Http::assertSent(fn (Request $request): bool => $request->data()['parallel_tool_calls'] === false);
    });
});

it('sets usage correctly with automatic caching', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/cache-usage-automatic-caching'
    );

    $prompt = fake()->paragraphs(40, true);

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->asText();

    $two = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->asText();

    expect($two->usage)
        ->promptTokens->toEqual(1111 - 1024)
        ->completionTokens->toEqual(109)
        ->cacheWriteInputTokens->toEqual(null)
        ->cacheReadInputTokens->toEqual(1024);
});

it('uses meta to provide previous response id', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'previous_response_id' => 'resp_foo',
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'previous_response_id'))->toBe('resp_foo');

        return true;
    });
});

it('uses meta to set auto truncation', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'truncation' => 'auto',
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'truncation'))->toBe('auto');

        return true;
    });
});

it('uses meta to set service_tier', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $serviceTier = 'priority';

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'service_tier' => $serviceTier,
        ])
        ->asText();

    Http::assertSent(function (Request $request) use ($serviceTier): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'service_tier'))->toBe($serviceTier);

        return true;
    });
});

it('filters service_tier if null', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'service_tier' => null,
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect($body)->not()->toHaveKey('service_tier');

        return true;
    });
});

it('uses meta to set text_verbosity', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $textVerbosity = 'medium';

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'text_verbosity' => $textVerbosity,
        ])
        ->asText();

    Http::assertSent(function (Request $request) use ($textVerbosity): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'text.verbosity'))->toBe($textVerbosity);

        return true;
    });
});

it('filters text_verbosity if null', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'text_verbosity' => null,
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect($body)->not()->toHaveKey('text.verbosity');

        return true;
    });
});

it('can analyze images with detail parameter', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $image = Image::fromLocalPath('tests/Fixtures/diamond.png')
        ->withProviderOptions(['detail' => 'high']);

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What do you see in this image?', [$image])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $imageContent = $body['input'][0]['content'][1];

        expect($imageContent['type'])->toBe('input_image');
        expect($imageContent['detail'])->toBe('high');
        expect($imageContent['image_url'])->toStartWith('data:image/png;base64,');

        return true;
    });
});

it('omits detail parameter when not specified', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $image = Image::fromLocalPath('tests/Fixtures/diamond.png');

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What do you see in this image?', [$image])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $imageContent = $body['input'][0]['content'][1];

        expect($imageContent['type'])->toBe('input_image');
        expect($imageContent)->not->toHaveKey('detail');
        expect($imageContent['image_url'])->toStartWith('data:image/png;base64,');

        return true;
    });
});

it('can analyze documents', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/text-response-with-document'
    );

    $document = Document::fromLocalPath('tests/Fixtures/test-pdf.pdf');

    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Summarize this document', [$document])
        ->asText();

    expect($response->text)->not->toBeEmpty();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $documentContent = $body['input'][0]['content'][1];

        expect($documentContent['type'])->toBe('input_file');
        expect($documentContent['filename'])->not->toBeEmpty();

        return true;
    });
});

it('sends reasoning effort when defined', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-reasoning-effort');

    $response = Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
            ],
        ])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'low');

    expect($response->additionalContent['reasoningSummaries'])->toBe([
        'I should introduce myself to the user.',
    ]);
});

it('maps withReasoning(false) to reasoning.effort=minimal', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-reasoning-effort');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withReasoning(false)
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'minimal');
});

it('does not include reasoning key when withReasoning is not called', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-reasoning-effort');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(function (Request $request): true {
        expect($request->data())->not->toHaveKey('reasoning');

        return true;
    });
});

it('honors explicit reasoning provider option over withReasoning(false)', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-reasoning-effort');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions(['reasoning' => ['effort' => 'high']])
        ->withReasoning(false)
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'high');
});

describe('provider tool results', function (): void {
    it('hands back the image a HOSTED image tool generated', function (): void {
        // The asymmetry this closes: every other hosted tool's output reached
        // additionalContent, and a turn that generated an image got the bytes
        // only inside `raw` — the untyped escape hatch, not a surface. So a
        // caller using the web_search tool was served and a caller using the
        // image_generation tool was not.
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-image-generation');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-5.2')
            ->withPrompt('Draw me a prism refracting daylight')
            ->withProviderTools([new ProviderTool(type: 'image_generation', name: 'image_generation')])
            ->asText();

        $step = $response->steps[0];

        expect($step->additionalContent)->toHaveKey('generatedImages');
        expect($step->additionalContent['generatedImages'])->toHaveCount(1);

        $image = $step->additionalContent['generatedImages'][0];

        // The SAME value object the images endpoint returns. A caller should not
        // have to know which of the two produced it.
        expect($image)->toBeInstanceOf(GeneratedImage::class)
            ->and($image->base64)->toBe('aVdBTUEAALGPC/xhBQAAAAlwSFlzAAALEwAACxMBAJqcGAAAAAd0SU1FB+QA')
            ->and($image->hasRevisedPrompt())->toBeTrue()
            ->and($image->revisedPrompt)->toContain('photorealistic prism');
    });

    it('reports what the provider ACTUALLY drew, not what was asked for', function (): void {
        // Kept separate from the image because this is the part a caller
        // reconciles against its request: a provider that silently served a
        // different size or quality is visible only here.
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-image-generation');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-5.2')
            ->withPrompt('Draw me a prism refracting daylight')
            ->withProviderTools([new ProviderTool(type: 'image_generation', name: 'image_generation')])
            ->asText();

        $call = $response->steps[0]->additionalContent['imageGenerationCalls'][0];

        expect($call['status'])->toBe('completed')
            ->and($call['size'])->toBe('1024x1024')
            ->and($call['quality'])->toBe('high')
            ->and($call['output_format'])->toBe('png')
            ->and($call['id'])->toStartWith('ig_');
    });

    it('adds no image keys to a turn that generated none', function (): void {
        // The vacuity guard for the two above. Without it a handler that always
        // emitted an empty array would pass them both, and a caller checking
        // `toHaveKey('generatedImages')` would branch on every turn.
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        expect($response->steps[0]->additionalContent)->not->toHaveKey('generatedImages')
            ->and($response->steps[0]->additionalContent)->not->toHaveKey('imageGenerationCalls');
    });

    it('captures web search provider tool in providerToolCalls', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        expect($response->steps)->toHaveCount(1);
        expect($response->steps[0]->providerToolCalls)->toHaveCount(1);

        $providerTool = $response->steps[0]->providerToolCalls[0];
        expect($providerTool)->toBeInstanceOf(ProviderToolCall::class);
        expect($providerTool->type)->toBe('web_search_call');
        expect($providerTool->status)->toBe('completed');
        expect($providerTool->id)->toStartWith('ws_');
        expect($providerTool->data)->toHaveKey('action');
        expect($providerTool->data['action']['type'])->toBe('search');
        expect($providerTool->data['action']['query'])->toContain('London weather');

        expect($response->steps[0]->additionalContent)->toHaveKey('searchQueries');
        expect($response->steps[0]->additionalContent['searchQueries'])->toBe(['London weather forecast today']);

        // open_page and find_in_page arrays should be empty for this fixture (only has search actions)
        expect($response->steps[0]->additionalContent)->not->toHaveKey('openPageUrls');
        expect($response->steps[0]->additionalContent)->not->toHaveKey('findInPagePatterns');
    });

    it('extracts openPageUrls and findInPagePatterns from web search actions', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-mixed-actions');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-5.2')
            ->withPrompt('Go to https://prismphp.com and find information about providers. Search the page for "OpenAI" to find which providers are supported.')
            ->withProviderTools([new ProviderTool(type: 'web_search', name: 'web_search')])
            ->asText();

        $step = $response->steps[0];

        // This fixture has open_page and find_in_page actions, but no search actions
        expect($step->additionalContent)->not->toHaveKey('searchQueries');

        // Verify openPageUrls contains URLs from open_page actions
        expect($step->additionalContent)->toHaveKey('openPageUrls');
        expect($step->additionalContent['openPageUrls'])->toBe([
            'https://prismphp.com/',
            'https://prismphp.com/getting-started/introduction.html',
        ]);

        // Verify findInPagePatterns contains patterns from find_in_page actions
        expect($step->additionalContent)->toHaveKey('findInPagePatterns');
        expect($step->additionalContent['findInPagePatterns'])->toBe(['OpenAI']);

        // Verify providerToolCalls contains all the raw action data
        expect($step->providerToolCalls)->toHaveCount(3);
        expect($step->providerToolCalls[0]->type)->toBe('web_search_call');
        expect($step->providerToolCalls[0]->data['action']['type'])->toBe('open_page');
        expect($step->providerToolCalls[1]->data['action']['type'])->toBe('open_page');
        expect($step->providerToolCalls[2]->data['action']['type'])->toBe('find_in_page');
    });

    it('captures code interpreter provider tool in providerToolCalls', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call-and-provider-tool');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        ];

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1')
            ->withPrompt('If the current temperature in Detroit is X, what is Y in the following equation: 3x + 10 = Y?')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->withMaxSteps(3)
            ->asText();

        $stepWithProviderTool = $response->steps[1];
        expect($stepWithProviderTool->providerToolCalls)->toHaveCount(1);

        $providerTool = $stepWithProviderTool->providerToolCalls[0];
        expect($providerTool)->toBeInstanceOf(ProviderToolCall::class);
        expect($providerTool->type)->toBe('code_interpreter_call');
        expect($providerTool->status)->toBe('completed');
        expect($providerTool->id)->toStartWith('ci_');
        expect($providerTool->data)->toHaveKey('code');
        expect($providerTool->data['code'])->toContain('3 * x + 10');
    });

    it('sets finish reason to ToolCalls when provider tools are present', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Stop);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Stop);
    });

    it('includes provider tool calls in assistant message additionalContent', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today?')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        $assistantMessage = $response->messages->last();
        expect($assistantMessage->additionalContent)->toHaveKey('provider_tool_calls');
        expect($assistantMessage->additionalContent['provider_tool_calls'])->toHaveCount(1);
        expect($assistantMessage->additionalContent['provider_tool_calls'][0])->toBeInstanceOf(ProviderToolCall::class);
    });

    it('handles mixed function calls and provider tools', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call-and-provider-tool');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        ];

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1')
            ->withPrompt('If the current temperature in Detroit is X, what is Y in the following equation: 3x + 10 = Y?')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->withMaxSteps(3)
            ->asText();

        expect($response->steps)->toHaveCount(2);

        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('weather');
        expect($firstStep->providerToolCalls)->toHaveCount(0);

        $secondStep = $response->steps[1];
        expect($secondStep->toolCalls)->toHaveCount(0);
        expect($secondStep->providerToolCalls)->toHaveCount(1);
        expect($secondStep->providerToolCalls[0]->type)->toBe('code_interpreter_call');

        // Verify the assistant message from step 1 is present in step 2's input messages
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(1);
        expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('weather');
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);
    });
});

describe('citations', function (): void {
    it('adds citations to additionalContent on response steps and assistant message for the web search tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today? Please provide citations.')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        $citationChunk = Arr::first(
            $response->additionalContent['citations'],
            fn (MessagePartWithCitations $part): bool => $part->citations !== [] && $part->citations[0]->sourceType === CitationSourceType::Url
        );

        expect($citationChunk->outputText)->toContain('temperatures');
        expect($citationChunk->citations)->toHaveCount(1);
        expect($citationChunk->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($citationChunk->citations[0]->sourceTitle)->toContain('weather');
        expect($citationChunk->citations[0]->source)->toBe('https://www.metcheck.com/WEATHER/dayforecast.asp?dateFor=07%2F06%2F2025&lat=51.508500&location=London&locationID=2364784&lon=-0.125700&utm_source=openai');

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(1);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(1);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('can handle citations on on a previous assistant message with a document', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations-included-in-turn');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today? Please provide citations.')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        $responseTwo = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withMessages([
                ...$response->steps->last()->messages,
                new UserMessage('Is the source you have cited reliable?'),
            ])
            ->asText();

        expect($responseTwo->text)->toContain('Metcheck');
    });
});

it('passes store parameter when specified', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $store = false;

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Give me TLDR of this legal document')
        ->withProviderOptions([
            'store' => $store,
        ])
        ->asText();

    Http::assertSent(function (Request $request) use ($store): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'store'))->toBe($store);

        return true;
    });
});

it('does not loop infinitely when using specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-with-specific-tool-choice');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city): string => "72°F in {$city}");

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('What is the weather in New York?')
        ->withTools([$weatherTool])
        ->withToolChoice('weather')
        ->withMaxSteps(5)
        ->asText();

    // Should complete in 2 steps (tool call + final response), not 5 (maxSteps)
    expect($response->steps)->toHaveCount(2);
    expect($response->text)->not->toBeEmpty();
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('includes status details when max tokens exceeded', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-max-tokens-exceeded');

    try {
        Prism::text()
            ->using('openai', 'gpt-4o')
            ->withPrompt('Write a long essay')
            ->asText();
        $this->fail('Expected PrismException was not thrown');
    } catch (PrismException $e) {
        expect($e->getMessage())
            ->toContain('incomplete')
            ->toContain('reasoning model');
    }
});

it('throws Length when top-level status is incomplete even if last output is completed reasoning', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-incomplete-with-reasoning');

    try {
        Prism::text()
            ->using('openai', 'gpt-4o')
            ->withPrompt('Write something')
            ->asText();
        $this->fail('Expected PrismException was not thrown');
    } catch (PrismException $e) {
        expect($e->getMessage())
            ->toContain('max tokens exceeded')
            ->toContain('reasoning model');
    }
});

it('handles unknown finish reasons gracefully', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-unknown-finish-reason');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Hello')
        ->asText();

    expect($response->text)->toBe('Some response');
    expect($response->finishReason)->toBe(FinishReason::Unknown);
});
