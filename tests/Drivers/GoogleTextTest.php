<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Facades\Tool;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

define('GEMINI_MODEL', 'gemini-1.5-flash');

beforeEach(function (): void {
    config()->set('prism.providers.google.api_key', 'YOUR_TEST_API_KEY');
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence(sprintf('v1beta/models/%s:generateContent?key=%s', GEMINI_MODEL, 'YOUR_TEST_API_KEY'), 'google/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('google', GEMINI_MODEL)
        ->withPrompt('Hello, how are you?')();

    expect($response->usage->promptTokens)->toBe(6)
        ->and($response->usage->completionTokens)->toBe(43)
        ->and($response->response['model'])->toBe(GEMINI_MODEL)
        ->and($response->text)->toContain('I am an AI, so I don\'t have feelings');
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence(sprintf('v1beta/models/%s:generateContent?key=%s', GEMINI_MODEL, 'YOUR_TEST_API_KEY'), 'google/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using('google', GEMINI_MODEL)
        ->withSystemPrompt('You are Yoda. Always respond like the master Jedi.')
        ->withPrompt('Introduce yourself.')();

    expect($response->usage->promptTokens)->toBe(14)
        ->and($response->usage->completionTokens)->toBe(42)
        ->and($response->response['model'])->toBe(GEMINI_MODEL)
        ->and($response->text)->toContain('Hmm, introduce myself, you do');
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence(sprintf('v1beta/models/%s:generateContent?key=%s', GEMINI_MODEL, 'YOUR_TEST_API_KEY'), 'google/generate-text-with-multiple-tools');

    $tools = [
        Tool::as('get_weather')
            ->for('Get the weather for a city')
            ->withParameter('city', 'The city to get weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('Search for current events or data')
            ->withParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in Detroit'),
    ];

    $response = Prism::text()
        ->using('google', GEMINI_MODEL)
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')();

    // Assert steps
    expect($response->steps)->toHaveCount(3);

    // First step assertions
    $firstStep = $response->steps[0];
    expect($firstStep->text)->toBe('')
        ->and($firstStep->finishReason)->toBe(FinishReason::Length)
        ->and($firstStep->toolCalls)->toHaveCount(1)
        ->and($firstStep->toolCalls[0]->name)->toBe('search')
        ->and($firstStep->toolCalls[0]->arguments())->toBe(['query' => 'Detroit Tigers game time today'])
        ->and($firstStep->usage->promptTokens)->toBe(98)
        ->and($firstStep->usage->completionTokens)->toBe(17)
        ->and($firstStep->messages)->toHaveCount(2)
        ->and($firstStep->messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($firstStep->messages[1])->toBeInstanceOf(AssistantMessage::class);

    // Second step assertions
    $secondStep = $response->steps[1];
    expect($secondStep->text)->toBe('')
        ->and($secondStep->finishReason)->toBe(FinishReason::Length)
        ->and($secondStep->toolCalls)->toHaveCount(1)
        ->and($secondStep->toolCalls[0]->name)->toBe('get_weather')
        ->and($secondStep->toolCalls[0]->arguments())->toBe(['city' => 'Detroit'])
        ->and($secondStep->usage->promptTokens)->toBe(125)
        ->and($secondStep->usage->completionTokens)->toBe(15)
        ->and($secondStep->messages)->toHaveCount(3);

    // Third step assertions
    $thirdStep = $response->steps[2];
    expect($thirdStep->text)->toBe("You should be fine without a coat! \n")
        ->and($thirdStep->finishReason)->toBe(FinishReason::Length)
        ->and($thirdStep->toolCalls)->toBeEmpty()
        ->and($thirdStep->usage->promptTokens)->toBe(159)
        ->and($thirdStep->usage->completionTokens)->toBe(8)
        ->and($thirdStep->messages)->toHaveCount(4);

    expect($response->responseMessages)->toHaveCount(3)
        ->and($response->responseMessages)->each->toBeInstanceOf(AssistantMessage::class)
        ->and($response->usage->promptTokens)->toBe(382)
        ->and($response->usage->completionTokens)->toBe(40)
        ->and($response->text)->toBe("You should be fine without a coat! \n");
});

it('throws an exception for invalid model name', function (): void {
    FixtureResponse::fakeResponseSequence(sprintf('v1beta/models/%s:generateContent?key=%s', 'not-a-model', 'YOUR_TEST_API_KEY'), 'google/invalid-model');
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('models/not-a-model is not found for API');

    Prism::text()
        ->using('google', 'not-a-model')
        ->withPrompt('This should fail due to invalid model')();
});

it('throws an exception for a missing api key', function (): void {
    config()->set('prism.providers.google.api_key', '');
    FixtureResponse::fakeResponseSequence(sprintf('v1beta/models/%s:generateContent?key=', GEMINI_MODEL), 'google/missing-api-key');
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage("[403] Method doesn't allow unregistered callers");

    Prism::text()
        ->using('google', GEMINI_MODEL)
        ->withPrompt('This should fail due to invalid model')();
});
