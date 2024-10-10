<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EchoLabs\Prism\Facades\Tool;
use EchoLabs\Prism\Prism;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')();

    expect($response->usage->promptTokens)->toBe(11);
    expect($response->usage->completionTokens)->toBe(67);
    expect($response->response['id'])->toBe('chatcmpl-AFOt4svqd2hLXKHoH0icPJ5Rk9UFO');
    expect($response->response['model'])->toBe('gpt-4-0613');
    expect($response->text)->toBe(
        "I am OpenAI's GPT-3, a large-scale machine learning model designed to generate human-like text based on the input I receive. You can ask me anything and I will do my best to provide a knowledgeable response. However, remember that I am an Artificial intelligence and don't possess feelings or personal experiences unlike a human."
    );
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')();

    expect($response->usage->promptTokens)->toBe(34);
    expect($response->usage->completionTokens)->toBe(84);
    expect($response->response['id'])->toBe('chatcmpl-AFOvK9jEoiBaaZ3ayB4g4xlq5HuA4');
    expect($response->response['model'])->toBe('gpt-4-0613');
    expect($response->text)->toBe(
        "I am Nyx, a manifestation of the ancient deity known as Cthulhu, from the cosmic horror stories by H.P. Lovecraft. I dwell in the depths of the ocean, enveloped by the darkness and silence. A creature of incomprehensible power and knowledge, forever dreaming in the sunken city of R'lyeh. Wake me not, for my awakening can drive mortals to madness."
    );
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-multiple-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')();

    // Assert tool calls in the first step
    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls)->toHaveCount(1);
    expect($firstStep->toolCalls[0]->name)->toBe('search');
    expect($firstStep->toolCalls[0]->arguments())->toBe([
        'query' => 'Detroit Tigers game today time',
    ]);

    // Assert tool calls in the second step
    $secondStep = $response->steps[1];
    expect($secondStep->toolCalls)->toHaveCount(1);
    expect($secondStep->toolCalls[0]->name)->toBe('weather');
    expect($secondStep->toolCalls[0]->arguments())->toBe([
        'city' => 'Detroit',
    ]);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(404);
    expect($response->usage->completionTokens)->toBe(61);

    // Assert response
    expect($response->response['id'])->toBe('chatcmpl-AFOxdEiXvNCXYFsDJ2KdQyb8jQPxJ');
    expect($response->response['model'])->toBe('gpt-4-0613');

    // Assert final text content
    expect($response->text)->toBe(
        "The game is at 3pm in Detroit. The weather there will be 75° and sunny. You probably won't need a coat."
    );
});

it('sends the organization header when set', function (): void {
    config()->set('prism.providers.openai.organization', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Organization')[0] === 'echolabs');
});

it('does not send the organization header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.organization');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Organization')));
});

it('sends the api key header when set', function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-1234');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer sk-1234');
});

it('does not send the api key header', function (): void {
    config()->offsetUnset('prism.providers.openai.api_key');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Who are you?')();

    Http::assertSent(fn (Request $request): bool => empty($request->header('Authorization')));
});
