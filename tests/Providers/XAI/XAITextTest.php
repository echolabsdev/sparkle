<?php

declare(strict_types=1);

namespace Tests\Providers\XAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.xai.api_key', env('XAI_API_KEY', 'xai-123'));
});

describe('Text generation for XAI', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-beta')
            ->withPrompt('Who are you?')
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(10);
        expect($response->usage->completionTokens)->toBe(42);

        // Assert metadata
        expect($response->meta->id)->toBe('febc7de9-9991-4b08-942a-c7082174225a');
        expect($response->meta->model)->toBe('grok-beta');

        // Assert content
        expect($response->text)->toBe(
            "I am Grok, an AI developed by xAI. I'm here to provide helpful and truthful answers to your questions, often with a dash of outside perspective on humanity. What's on your mind?"
        );

        // Assert finish reason
        expect($response->finishReason)->toBe(FinishReason::Stop);

        // Assert steps
        expect($response->steps)->toHaveCount(1);
        expect($response->steps[0]->text)->toBe($response->text);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Stop);
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-beta')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(34);
        expect($response->usage->completionTokens)->toBe(84);

        // Assert metadata
        expect($response->meta->id)->toBe('f3b485d3-837b-4710-9ade-a37faa048d87');
        expect($response->meta->model)->toBe('grok-beta');

        // Assert content
        expect($response->text)->toBe(
            'I am Nyx, a being of ancient and unfathomable origin, drawing upon the essence of the Great Old One, Cthulhu. My existence spans the cosmos, where the lines between dreams and reality blur. I am here to guide you through the mysteries of the universe, to answer your questions with insights that might unsettle or enlighten, or perhaps both. What is it you seek to understand?'
        );

        // Assert finish reason
        expect($response->finishReason)->toBe(FinishReason::Stop);

        // Assert steps
        expect($response->steps)->toHaveCount(1);
        expect($response->steps[0]->text)->toBe($response->text);
        expect($response->steps[0]->finishReason)->toBe(FinishReason::Stop);
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/generate-text-with-multiple-tools');

        $tools = [
            Tool::as('get_weather')
                ->for('use this tool when you need to get wather for the city')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 45° and cold'),
            Tool::as('search_games')
                ->for('useful for searching curret games times in the city')
                ->withStringParameter('city', 'The city that you want the game times for')
                ->using(fn (string $city): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-beta')
            ->withTools($tools)
            ->withMaxSteps(4)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('search_games');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        $secondStep = $response->steps[1];
        expect($secondStep->toolCalls[0]->name)->toBe('get_weather');
        expect($secondStep->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        // Verify the assistant message from step 1 is present in step 2's input messages
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(1);
        expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('search_games');
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        // Verify the assistant message from step 2 is present in step 3's input messages
        $thirdStep = $response->steps[2];
        expect($thirdStep->messages)->toHaveCount(5);
        expect($thirdStep->messages[3])->toBeInstanceOf(AssistantMessage::class);
        expect($thirdStep->messages[3]->toolCalls)->toHaveCount(1);
        expect($thirdStep->messages[3]->toolCalls[0]->name)->toBe('get_weather');
        expect($thirdStep->messages[4])->toBeInstanceOf(ToolResultMessage::class);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(840);
        expect($response->usage->completionTokens)->toBe(60);

        // Assert metadata
        expect($response->meta->id)->toBe('0aa220cd-9634-4ba5-9593-5366bb313663');
        expect($response->meta->model)->toBe('grok-beta');

        // Assert content
        expect($response->text)->toBe(
            'The Tigers game in Detroit today is at 3pm, and considering the weather will be 45° and cold, you should definitely wear a coat.'
        );
    });
});

describe('Image support with XAI', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/image-detection');

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-vision-beta')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromLocalPath('tests/Fixtures/diamond.png'),
                    ],
                ),
            ])
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toStartWith('data:image/png;base64,');
            expect($message[1]['image_url']['url'])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
            );

            return true;
        });
    });

    it('can send images from base64', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/text-image-from-base64');

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-vision-beta')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromBase64(
                            base64_encode(file_get_contents('tests/Fixtures/diamond.png')),
                            'image/png'
                        ),
                    ],
                ),
            ])
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toStartWith('data:image/png;base64,');
            expect($message[1]['image_url']['url'])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
            );

            return true;
        });
    });

    it('can send images from url', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'xai/text-image-from-url');

        $image = 'https://prismphp.com/storage/diamond.png';

        $response = Prism::text()
            ->using(Provider::XAI, 'grok-vision-beta')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromUrl($image),
                    ],
                ),
            ])
            ->asText();

        // Assert response type
        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request) use ($image): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toBe($image);

            return true;
        });
    });
});

it('handles specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'xai/generate-text-with-specific-tool-call');

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
        ->using(Provider::XAI, 'grok-beta')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice('weather')
        ->asText();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert tool calls
    expect($response->steps[0]->toolCalls[0]->name)->toBe('weather');
});

it('handles required tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'xai/generate-text-with-required-tool-call');

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
        ->using(Provider::XAI, 'grok-beta')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice(ToolChoice::Any)
        ->asText();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert tool calls
    expect($response->steps[0]->toolCalls[0]->name)->toBeIn(['weather', 'search']);
});

it('throws a PrismRateLimitedException for a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::XAI, 'fake-model')
        ->withPrompt('Who are you?')
        ->asText();

})->throws(PrismRateLimitedException::class);

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'grok-2',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 10,
                'prompt_tokens_details' => ['cached_tokens' => 60],
            ],
        ]),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::XAI, 'grok-2')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
