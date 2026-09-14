<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.qwen.api_key', env('QWEN_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withPrompt('Who are you?')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_contains($request->url(), 'text-generation/generation')
            && $data['model'] === 'qwen-plus'
            && $data['input']['messages'][0]['role'] === 'user'
            && $data['input']['messages'][0]['content'] === 'Who are you?'
            && $data['parameters']['result_format'] === 'message'
            && ! isset($data['parameters']['tools']);
    });

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(12);
    expect($response->usage->completionTokens)->toBe(135);

    // Assert metadata
    expect($response->meta->id)->toBe('e56ea9d3-9cc7-9aa1-97fc-3affd9815a94');
    expect($response->meta->model)->toBe('qwen-plus');

    expect($response->text)->toContain("I'm Qwen");

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $messages = $data['input']['messages'];

        return $messages[0]['role'] === 'system'
            && $messages[0]['content'] === 'MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!'
            && $messages[1]['role'] === 'user'
            && $messages[1]['content'] === 'Who are you?';
    });

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(36);
    expect($response->usage->completionTokens)->toBe(361);

    // Assert metadata
    expect($response->meta->id)->toBe('bbb36202-7639-9a7b-9a45-7730b39e8b41');
    expect($response->meta->model)->toBe('qwen-plus');
    expect($response->text)->toContain('Nyx');

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/generate-text-with-multiple-tools');

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
        ->using(Provider::Qwen, 'qwen-plus')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->generate();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert tool calls in the first step
    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls)->toHaveCount(2);
    expect($firstStep->toolCalls[0]->name)->toBe('search');
    expect($firstStep->toolCalls[0]->arguments())->toBe([
        'query' => 'Tigers game schedule today',
    ]);

    expect($firstStep->toolCalls[1]->name)->toBe('weather');
    expect($firstStep->toolCalls[1]->arguments())->toBe([
        'city' => 'Detroit',
    ]);

    // There should be 2 steps
    expect($response->steps)->toHaveCount(2);

    // Verify the assistant message from step 1 is present in step 2's input messages
    $secondStep = $response->steps[1];
    expect($secondStep->messages)->toHaveCount(3);
    expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
    expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
    expect($secondStep->messages[1]->toolCalls)->toHaveCount(2);
    expect($secondStep->messages[1]->toolCalls[0]->name)->toBe('search');
    expect($secondStep->messages[1]->toolCalls[1]->name)->toBe('weather');
    expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

    // Assert usage (cumulative across all steps)
    expect($response->usage->promptTokens)->toBe(570);
    expect($response->usage->completionTokens)->toBe(78);

    // Assert response
    expect($response->meta->id)->toBe('1a419c1a-8e16-9808-9ac2-5dfeffb2741f');
    expect($response->meta->model)->toBe('qwen-plus');

    // Assert final text content
    expect($response->text)->toContain('3 PM');
    expect($response->text)->toContain('75°');

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text with images using VL model (multimodal)', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'status_code' => 200,
            'request_id' => 'vl-req-001',
            'code' => '',
            'message' => '',
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'The first image shows a dog and a girl, the second shows a tiger, and the third shows a rabbit.',
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 1500,
                'output_tokens' => 25,
                'total_tokens' => 1525,
            ],
        ], 200),
    ]);

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-vl-max')
        ->withMessages([
            new UserMessage('这些是什么?', [
                Image::fromUrl('https://dashscope.oss-cn-beijing.aliyuncs.com/images/dog_and_girl.jpeg'),
                Image::fromUrl('https://dashscope.oss-cn-beijing.aliyuncs.com/images/tiger.png'),
                Image::fromUrl('https://dashscope.oss-cn-beijing.aliyuncs.com/images/rabbit.png'),
            ]),
        ])
        ->generate();

    expect($response)->toBeInstanceOf(TextResponse::class);
    expect($response->text)->toContain('dog');
    expect($response->usage->promptTokens)->toBe(1500);
    expect($response->usage->completionTokens)->toBe(25);
    expect($response->meta->id)->toBe('vl-req-001');
    expect($response->finishReason)->toBe(FinishReason::Stop);

    // Assert it routes to the multimodal endpoint (not text-generation)
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $content = $data['input']['messages'][0]['content'];

        return str_contains($request->url(), 'multimodal-generation/generation')
            && ! str_contains($request->url(), 'text-generation')
            && is_array($content)
            && count($content) === 4
            && $content[0]['image'] === 'https://dashscope.oss-cn-beijing.aliyuncs.com/images/dog_and_girl.jpeg'
            && $content[1]['image'] === 'https://dashscope.oss-cn-beijing.aliyuncs.com/images/tiger.png'
            && $content[2]['image'] === 'https://dashscope.oss-cn-beijing.aliyuncs.com/images/rabbit.png'
            && $content[3]['text'] === '这些是什么?';
    });
});

it('routes to text-generation endpoint when no images present', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withPrompt('Hello')
        ->generate();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'text-generation/generation')
        && ! str_contains($request->url(), 'multimodal-generation'));
});

it('sends generation parameters correctly', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withMaxTokens(500)
        ->usingTemperature(0.7)
        ->usingTopP(0.9)
        ->withPrompt('Hello')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['parameters']['max_tokens'] === 500
            && $data['parameters']['temperature'] === 0.7
            && $data['parameters']['top_p'] === 0.9
            && $data['parameters']['result_format'] === 'message';
    });
});

it('excludes cached tokens from promptTokens', function (): void {
    Http::fake([
        '*' => Http::response([
            'status_code' => 200,
            'request_id' => 'cache-test-1',
            'code' => '',
            'message' => '',
            'output' => [
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                    'finish_reason' => 'stop',
                ]],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 10,
                'total_tokens' => 110,
                'prompt_tokens_details' => ['cached_tokens' => 60],
            ],
        ]),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using(Provider::Qwen, 'qwen-plus')
        ->withPrompt('Hello')
        ->asText();

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});
