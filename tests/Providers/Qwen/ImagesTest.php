<?php

declare(strict_types=1);

namespace Tests\Providers\Qwen;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.qwen.api_key', env('QWEN_API_KEY'));
    config()->set('prism.providers.qwen.url', env('QWEN_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'));
});

it('can generate an image with qwen-image-max', function (): void {
    FixtureResponse::fakeResponseSequence(
        'multimodal-generation/generation',
        'qwen/image-generation'
    );

    $response = Prism::image()
        ->using('qwen', 'qwen-image-max')
        ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
        ->withProviderOptions([
            'size' => '1328*1328',
        ])
        ->withClientOptions(['timeout' => 120])
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->firstImage()->url)->toContain('dashscope-result');
    expect($response->imageCount())->toBe(1);
    expect($response->meta->id)->toBe('5e859e62-93a1-4222-b9ea-b4e2ac543e1a');
    expect($response->meta->model)->toBe('qwen-image-max');
    expect($response->additionalContent)->toBe([
        'image_count' => 1,
        'width' => 1328,
        'height' => 1328,
    ]);
});

it('can generate an image with provider options', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => [
                                [
                                    'image' => 'https://dashscope-result.oss-cn-shanghai.aliyuncs.com/options-image.png',
                                ],
                            ],
                            'role' => 'assistant',
                        ],
                    ],
                ],
            ],
            'usage' => [
                'image_count' => 1,
                'width' => 928,
                'height' => 1664,
            ],
            'request_id' => 'req-img-004',
        ], 200),
    ]);

    $response = Prism::image()
        ->using('qwen', 'qwen-image-max')
        ->withPrompt('A sunset over mountains')
        ->withProviderOptions([
            'size' => '928*1664',
            'negative_prompt' => 'low resolution, low quality',
            'prompt_extend' => true,
            'watermark' => false,
            'seed' => 42,
        ])
        ->generate();

    expect($response->firstImage()->url)->toBe('https://dashscope-result.oss-cn-shanghai.aliyuncs.com/options-image.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'qwen-image-max'
            && $data['parameters']['size'] === '928*1664'
            && $data['parameters']['negative_prompt'] === 'low resolution, low quality'
            && $data['parameters']['prompt_extend'] === true
            && $data['parameters']['watermark'] === false
            && $data['parameters']['seed'] === 42;
    });
});

it('sends request to correct dashscope native api endpoint', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => [
                                [
                                    'image' => 'https://dashscope-result.oss-cn-shanghai.aliyuncs.com/test.png',
                                ],
                            ],
                            'role' => 'assistant',
                        ],
                    ],
                ],
            ],
            'usage' => [
                'image_count' => 1,
                'width' => 1664,
                'height' => 928,
            ],
            'request_id' => 'req-endpoint-006',
        ], 200),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-max')
        ->withPrompt('Test endpoint')
        ->generate();

    Http::assertSent(
        // Verify it uses the DashScope native API, not the OpenAI-compatible endpoint
        fn (Request $request): bool => str_contains($request->url(), '/api/v1/services/aigc/multimodal-generation/generation')
        && ! str_contains($request->url(), '/compatible-mode/'));
});

it('handles generation failure', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'code' => 'InvalidParameter',
            'message' => 'num_images_per_prompt must be 1',
        ], 400),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-max')
        ->withPrompt('This will fail')
        ->withProviderOptions([
            'n' => 3,
        ])
        ->generate();
})->throws(PrismException::class);

it('can edit an image with qwen-image-edit-max', function (): void {
    FixtureResponse::fakeResponseSequence(
        'multimodal-generation/generation',
        'qwen/image-edit'
    );

    $response = Prism::image()
        ->using('qwen', 'qwen-image-edit-max')
        ->withPrompt('生成一张符合深度图的图像，遵循以下描述：一辆红色的破旧的自行车停在一条泥泞的小路上', [
            Image::fromUrl('https://example.com/input-image.png'),
        ])
        ->withProviderOptions([
            'n' => 2,
            'size' => '1024*1536',
            'prompt_extend' => true,
            'watermark' => false,
        ])
        ->generate();

    expect($response->imageCount())->toBe(2);
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->firstImage()->url)->toContain('dashscope-result');
    expect($response->meta->id)->toBe('a1b2c3d4-5e6f-7890-abcd-ef1234567890');
    expect($response->additionalContent)->toBe([
        'image_count' => 2,
        'width' => 1024,
        'height' => 1536,
    ]);
});

it('sends image editing request with correct content structure', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => [
                                ['image' => 'https://dashscope-result.oss.aliyuncs.com/edited.png'],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['image_count' => 1, 'width' => 1024, 'height' => 1024],
            'request_id' => 'req-edit-001',
        ], 200),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-edit-max')
        ->withPrompt('Make the background blue', [
            Image::fromUrl('https://example.com/photo.png'),
        ])
        ->withProviderOptions([
            'n' => 1,
            'negative_prompt' => 'low quality',
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $content = $data['input']['messages'][0]['content'];

        // Image should come before text in the content array
        return $data['model'] === 'qwen-image-edit-max'
            && count($content) === 2
            && isset($content[0]['image'])
            && $content[0]['image'] === 'https://example.com/photo.png'
            && $content[1]['text'] === 'Make the background blue'
            && $data['parameters']['n'] === 1
            && $data['parameters']['negative_prompt'] === 'low quality';
    });
});

it('sends multiple images for multi-image fusion editing', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => [
                                ['image' => 'https://dashscope-result.oss.aliyuncs.com/fused.png'],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['image_count' => 1, 'width' => 1024, 'height' => 1536],
            'request_id' => 'req-edit-002',
        ], 200),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-edit-max')
        ->withPrompt('图1中的女生穿着图2中的黑色裙子按图3的姿势坐下', [
            Image::fromUrl('https://example.com/person.png'),
            Image::fromUrl('https://example.com/dress.png'),
            Image::fromUrl('https://example.com/pose.png'),
        ])
        ->withProviderOptions([
            'n' => 2,
            'size' => '1024*1536',
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $content = $data['input']['messages'][0]['content'];

        // 3 images + 1 text = 4 content items
        return count($content) === 4
            && $content[0]['image'] === 'https://example.com/person.png'
            && $content[1]['image'] === 'https://example.com/dress.png'
            && $content[2]['image'] === 'https://example.com/pose.png'
            && isset($content[3]['text']);
    });
});

it('handles base64 images in editing request', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => [
                                ['image' => 'https://dashscope-result.oss.aliyuncs.com/b64-edited.png'],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['image_count' => 1, 'width' => 1024, 'height' => 1024],
            'request_id' => 'req-edit-003',
        ], 200),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-edit-max')
        ->withPrompt('Add a hat', [
            Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk', 'image/png'),
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $content = $data['input']['messages'][0]['content'];

        return count($content) === 2
            && str_starts_with((string) $content[0]['image'], 'data:image/png;base64,')
            && $content[1]['text'] === 'Add a hat';
    });
});

it('generates request without parameters when no options provided', function (): void {
    Http::fake([
        'dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation' => Http::response([
            'output' => [
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => [
                                [
                                    'image' => 'https://dashscope-result.oss-cn-shanghai.aliyuncs.com/no-params.png',
                                ],
                            ],
                            'role' => 'assistant',
                        ],
                    ],
                ],
            ],
            'usage' => [
                'image_count' => 1,
                'width' => 1664,
                'height' => 928,
            ],
            'request_id' => 'req-no-params-008',
        ], 200),
    ]);

    Prism::image()
        ->using('qwen', 'qwen-image-max')
        ->withPrompt('Simple prompt')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'qwen-image-max'
            && ! isset($data['parameters']);
    });
});
