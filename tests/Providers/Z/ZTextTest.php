<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

describe('Text generation for Z', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using(Provider::Z, 'z-model')
            ->withPrompt('Hello!')
            ->asText();

        expect($response->text)->toBe('Hello! How can I help you today?')
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->usage->promptTokens)->toBe(9)
            ->and($response->usage->completionTokens)->toBe(12)
            ->and($response->meta->id)->toBe('chatcmpl-123')
            ->and($response->meta->model)->toBe('z-model');
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using(Provider::Z, 'z-model')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class)
            ->and($response->usage->promptTokens)->toBe(1)
            ->and($response->usage->cacheReadInputTokens)->toBe(189)
            ->and($response->usage->completionTokens)->toBe(166)
            ->and($response->meta->id)->toBe('202512161952121dd7efde49d14dc9')
            ->and($response->meta->model)->toBe('z-model')
            ->and($response->text)->toBe(
                "\nI'm an AI assistant created to help with a wide range of tasks and questions. I can assist with things like:\n\n- Answering questions and providing information\n- Helping with research and analysis\n- Writing and editing content\n- Brainstorming ideas\n- Explaining complex topics\n- And much more\n\nI'm designed to be helpful, harmless, and honest in our interactions. I don't have personal experiences or emotions, but I'm here to assist you with whatever you need help with. \n\nIs there something specific I can help you with today?"
            )
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->steps)->toHaveCount(1)
            ->and($response->steps[0]->text)->toBe($response->text)
            ->and($response->steps[0]->finishReason)->toBe(FinishReason::Stop);
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/generate-text-with-multiple-tools');

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
            ->using(Provider::Z, 'z-model')
            ->withTools($tools)
            ->withMaxSteps(4)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class);

        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2)
            ->and($firstStep->toolCalls[0]->name)->toBe('search_games')
            ->and($firstStep->toolCalls[0]->arguments())->toBe([
                'city' => 'Detroit',
            ])
            ->and($firstStep->toolCalls[1]->name)->toBe('get_weather')
            ->and($firstStep->toolCalls[1]->arguments())->toBe([
                'city' => 'Detroit',
            ])
            ->and($response->usage->promptTokens)->toBe(72)
            ->and($response->usage->cacheReadInputTokens)->toBe(544)
            ->and($response->usage->completionTokens)->toBe(319)
            ->and($response->meta->id)->toBe('20251216203244b8311d53051b4c17')
            ->and($response->meta->model)->toBe('z-model')
            ->and($response->text)->toBe(
                "\nBased on the information I gathered:\n\n**Tigers Game Time:** The Tigers game today in Detroit is at 3:00 PM.\n\n**Weather and Coat Recommendation:** The weather will be 45° and cold. Yes, you should definitely wear a coat to the game! At 45 degrees, it will be quite chilly, especially if you'll be sitting outdoors for several hours. You might want to consider wearing a warm coat, and possibly dressing in layers with a hat and gloves for extra comfort during the game."
            );
    });
});

describe('Image support with Z', function (): void {
    it('can send images from url', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/text-image-from-url');

        $image = 'https://prismphp.com/storage/diamond.png';

        $response = Prism::text()
            ->using(Provider::Z, 'z-model.v')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromUrl($image),
                    ],
                ),
            ])
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request) use ($image): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])
                ->toBe([
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $image,
                    ],
                ])
                ->and($message[1])
                ->toBe([
                    'type' => 'text',
                    'text' => 'What is this image',
                ]);

            return true;
        });
    });

    it('can send file from url', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/text-file-from-url');

        $file = 'https://cdn.bigmodel.cn/static/demo/demo2.txt';

        $response = Prism::text()
            ->using(Provider::Z, 'z-model.v')
            ->withMessages([
                new UserMessage(
                    'What are the files show about?',
                    additionalContent: [
                        Document::fromUrl($file),
                    ],
                ),
            ])
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request) use ($file): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])
                ->toBe([
                    'type' => 'file_url',
                    'file_url' => [
                        'url' => $file,
                    ],
                ])
                ->and($message[1])
                ->toBe([
                    'type' => 'text',
                    'text' => 'What are the files show about?',
                ]);

            return true;
        });
    });

    it('can send video from url', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/text-video-from-url');

        $videoUrl = 'https://cdn.bigmodel.cn/agent-demos/lark/113123.mov';

        $response = Prism::text()
            ->using(Provider::Z, 'z-model.v')
            ->withMessages([
                new UserMessage(
                    'What are the video show about?',
                    additionalContent: [
                        Video::fromUrl($videoUrl),
                    ],
                ),
            ])
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class);

        Http::assertSent(function (Request $request) use ($videoUrl): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])
                ->toBe([
                    'type' => 'video_url',
                    'video_url' => [
                        'url' => $videoUrl,
                    ],
                ])
                ->and($message[1])
                ->toBe([
                    'type' => 'text',
                    'text' => 'What are the video show about?',
                ]);

            return true;
        });
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/generate-text-with-required-tool-call');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching current events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using(Provider::Z, 'z-model')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice(ToolChoice::Any)
            ->asText();

        expect($response)->toBeInstanceOf(TextResponse::class)
            ->and($response->steps[0]->toolCalls[0]->name)->toBeIn(['weather', 'search']);
    });

    it('throws a PrismRateLimitedException for a 429 response code', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using(Provider::Z, 'z-model')
            ->withPrompt('Who are you?')
            ->asText();

    })->throws(PrismRateLimitedException::class);
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'cache-test-1',
            'model' => 'glm-5',
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
        ->using(Provider::Z, 'glm-5')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
