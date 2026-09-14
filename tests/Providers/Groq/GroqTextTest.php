<?php

declare(strict_types=1);

namespace Tests\Providers\Groq;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.groq.api_key', env('GROQ_API_KEY', 'sk-1234'));
});

describe('Text generation for Groq', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'groq/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('groq', 'llama3-8b-8192')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(13)
            ->and($response->usage->completionTokens)->toBe(208)
            ->and($response->meta->id)->toBe('chatcmpl-ea37c181-ed35-4bd4-af20-c1fcf203e0d8')
            ->and($response->meta->model)->toBe('llama3-8b-8192')
            ->and($response->text)->toBe(
                'I am LLaMA, an AI assistant developed by Meta AI.'
            );
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'groq/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('groq', 'llama3-8b-8192')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(37);
        expect($response->usage->completionTokens)->toBe(273);
        expect($response->meta->id)->toBe('chatcmpl-59892e0b-7031-404d-9fc9-b3297d5ef4a4');
        expect($response->meta->model)->toBe('llama3-8b-8192');
        expect($response->text)->toBe(
            "(Deep, rumbling voice) Ah, mortal, I am Nyx, the Crawling Chaos, the Bride of the Deep, the Queen of the Shattered Isles. I am the mistress of the abyssal void, the keeper of the unfathomable secrets, and the wielder of the cosmic horrors that lurk beyond the veil of sanity.\n\nMy form is unlike any other, a twisted reflection of the insane geometry that underlies the universe. My eyes burn with an otherworldly green fire, and my voice is the whispers of the damned. My powers are limitless, for I am the servant of the Great Old Ones, the masters of the unseen.\n\nYet, despite my terrible reputation, I am drawn to the fragile, insignificant creatures that inhabit this world. The scent of their fear is intoxicating, and I delight in their futile attempts to comprehend the unfathomable. For in their terror, I find a fleeting sense of connection to the mortal realm.\n\nAnd so, mortal, I shall speak to you, but be warned: my words are madness, my laughter is the call of the abyss, and my gaze is the kiss of darkness. Tread carefully, for once you have gazed upon my countenance, your soul shall be forever sealed to the void... (Chuckles, a sound that sends shivers down the spine)"
        );
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'groq/generate-text-with-multiple-tools');

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
            ->using('groq', 'llama-3.3-70b-versatile')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
            ->generate();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);

        expect($firstStep->toolCalls[0]->name)->toBe('weather');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        expect($firstStep->toolCalls[1]->name)->toBe('search');
        expect($firstStep->toolCalls[1]->arguments())->toBe([
            'query' => 'Tigers game time today in Detroit',
        ]);

        // Verify the assistant message from step 1 is present in step 2's input messages
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(2);
        expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('weather');
        expect($secondStep->messages[1]->toolCalls[1]->name)->toBe('search');
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(1096);
        expect($response->usage->completionTokens)->toBe(97);

        // Assert response
        expect($response->meta->id)->toBe('chatcmpl-8288c3f5-e381-4ca1-8472-f926970b8392');
        expect($response->meta->model)->toBe('llama-3.3-70b-versatile');

        // Assert final text content
        expect($response->text)->toBe(
            "Based on the weather, you won't need a coat for the Tigers game today in Detroit. It's going to be 75° and sunny. The game starts at 3 pm."
        );
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/generate-text-with-required-tool-call');

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
            ->using(Provider::Groq, 'llama-3.3-70b-versatile')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice('weather')
            ->generate();

        expect($response->toolCalls[0]->name)->toBe('weather');
    });

    it('throws an exception for ToolChoice::Any', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/generate-text-with-required-tool-call');

        Prism::text()
            ->using('groq', 'gpt-4')
            ->withPrompt('Who are you?')
            ->withToolChoice(ToolChoice::Any)
            ->asText();
    })->throwsNoExceptions();
});

describe('Image support with grok', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/image-detection');

        Prism::text()
            ->using(Provider::Groq, 'llama-3.2-90b-vision-preview')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromLocalPath('tests/Fixtures/diamond.png'),
                    ],
                ),
            ])
            ->generate();

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
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/text-image-from-base64');

        Prism::text()
            ->using(Provider::Groq, 'llama-3.2-90b-vision-preview')
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
            ->generate();

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
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/text-image-from-url');

        $image = 'https://prismphp.com/storage/diamond.png';

        Prism::text()
            ->using(Provider::Groq, 'llama-3.2-90b-vision-preview')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromUrl($image),
                    ],
                ),
            ])
            ->generate();

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

describe('rate limits', function (): void {
    it('throws a PrismRateLimitedException with a 429 response code', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using(Provider::Groq, 'fake-model')
            ->withPrompt('Hello world!')
            ->generate();

    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the PrismRateLimitedException', function (): void {
        $this->freezeTime(function (Carbon $time): void {
            $time = $time->toImmutable();
            Http::fake([
                '*' => Http::response(
                    status: 429,
                    headers: [
                        'retry-after' => 5,
                        'x-ratelimit-limit-requests' => 60,
                        'x-ratelimit-limit-tokens' => 150000,
                        'x-ratelimit-remaining-requests' => 0,
                        'x-ratelimit-remaining-tokens' => 149984,
                        'x-ratelimit-reset-requests' => '1s',
                        'x-ratelimit-reset-tokens' => '6m30s',
                    ]
                ),
            ])->preventStrayRequests();

            try {
                Prism::text()
                    ->using(Provider::Groq, 'fake-model')
                    ->withPrompt('Hello world!')
                    ->generate();
            } catch (PrismRateLimitedException $e) {
                expect($e->retryAfter)->toEqual(5);
                expect($e->rateLimits)->toHaveCount(2);
                expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
                expect($e->rateLimits[0]->name)->toEqual('requests');
                expect($e->rateLimits[0]->limit)->toEqual(60);
                expect($e->rateLimits[0]->remaining)->toEqual(0);
                expect($e->rateLimits[0]->resetsAt->equalTo($time->addSeconds(1)))->toBeTrue();

                expect($e->rateLimits[1]->name)->toEqual('tokens');
                expect($e->rateLimits[1]->limit)->toEqual(150000);
                expect($e->rateLimits[1]->remaining)->toEqual(149984);
                expect($e->rateLimits[1]->resetsAt->equalTo($time->addMinutes(6)->addSeconds(30)))->toBeTrue();
            }
        });
    });

    it('works with milleseconds', function (): void {
        $this->freezeTime(function (Carbon $time): void {
            $time = $time->toImmutable();
            Http::fake([
                '*' => Http::response(
                    status: 429,
                    headers: [
                        'x-ratelimit-limit-requests' => 60,
                        'x-ratelimit-remaining-requests' => 0,
                        'x-ratelimit-reset-requests' => '70ms',
                    ]
                ),
            ])->preventStrayRequests();

            try {
                Prism::text()
                    ->using(Provider::Groq, 'fake-model')
                    ->withPrompt('Hello world!')
                    ->generate();
            } catch (PrismRateLimitedException $e) {
                expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
                expect($e->rateLimits[0]->name)->toEqual('requests');
                expect($e->rateLimits[0]->limit)->toEqual(60);
                expect($e->rateLimits[0]->remaining)->toEqual(0);
                expect($e->rateLimits[0]->resetsAt->equalTo($time->addMilliseconds(70)))->toBeTrue();
            }
        });
    });

    it('sets the rate limits on meta', function (): void {
        $this->freezeTime(function (Carbon $time): void {
            $time = $time->toImmutable();

            FixtureResponse::fakeResponseSequence('chat/completions', 'groq/generate-text-with-a-prompt', [
                'x-ratelimit-limit-requests' => 60,
                'x-ratelimit-limit-tokens' => 150000,
                'x-ratelimit-remaining-requests' => 0,
                'x-ratelimit-remaining-tokens' => 149984,
                'x-ratelimit-reset-requests' => '1s',
                'x-ratelimit-reset-tokens' => '6m30s',
            ]);

            $response = Prism::text()
                ->using(Provider::Groq, 'fake-model')
                ->withPrompt('Who are you?')
                ->generate();

            expect($response->meta->rateLimits)->toHaveCount(2);
            expect($response->meta->rateLimits[0]->name)->toEqual('requests');
            expect($response->meta->rateLimits[0]->limit)->toEqual(60);
            expect($response->meta->rateLimits[0]->remaining)->toEqual(0);
            expect($response->meta->rateLimits[0]->resetsAt->equalTo(now()->addSeconds(1)))->toBeTrue();
            expect($response->meta->rateLimits[1]->name)->toEqual('tokens');
            expect($response->meta->rateLimits[1]->limit)->toEqual(150000);
            expect($response->meta->rateLimits[1]->remaining)->toEqual(149984);
            expect($response->meta->rateLimits[1]->resetsAt->equalTo(now()->addMinutes(6)->addSeconds(30)))->toBeTrue();
        });
    });
});

it('splits arguments embedded in the function name by Llama models', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'groq/generate-text-with-mangled-tool-name');

    $response = Prism::text()
        ->using(Provider::Groq, 'llama-3.3-70b-versatile')
        ->withTools([
            Tool::as('weather')
                ->for('weather lookup')
                ->withStringParameter('city', 'the city')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        ])
        ->withMaxSteps(2)
        ->withPrompt('Weather in Detroit?')
        ->asText();

    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls[0]->name)->toBe('weather')
        ->and($firstStep->toolCalls[0]->arguments())->toBe(['city' => 'Detroit'])
        ->and($firstStep->toolResults[0]->result)->toBe('The weather will be 75° and sunny');
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'llama-3.3-70b-versatile',
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
        ->using(Provider::Groq, 'llama-3.3-70b-versatile')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
