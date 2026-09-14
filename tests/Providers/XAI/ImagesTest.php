<?php

declare(strict_types=1);

namespace Tests\Providers\XAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.xai.api_key', env('XAI_API_KEY', 'xai-123'));
});

it('can generate an image with grok-imagine-image', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/generated-image.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->url)->toBe('https://example.com/generated-image.png');
    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->usage->promptTokens)->toBe(15);
    expect($response->imageCount())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.x.ai/v1/images/generations' &&
               $data['model'] === 'grok-imagine-image' &&
               $data['prompt'] === 'A cute baby sea otter';
    });
});

it('can generate an image with base64 response format', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A mountain sunset')
        ->withProviderOptions([
            'response_format' => 'b64_json',
        ])
        ->generate();

    expect($response->firstImage())->not->toBeNull();
    expect($response->firstImage()->base64)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    expect($response->firstImage()->hasBase64())->toBeTrue();
    expect($response->firstImage()->hasUrl())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'grok-imagine-image' &&
               $data['prompt'] === 'A mountain sunset' &&
               $data['response_format'] === 'b64_json';
    });
});

it('forwards xai-specific options in request', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/wide-image.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 18,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A panoramic mountain landscape')
        ->withProviderOptions([
            'aspect_ratio' => '16:9',
            'resolution' => '2k',
        ])
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/wide-image.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'grok-imagine-image' &&
               $data['prompt'] === 'A panoramic mountain landscape' &&
               $data['aspect_ratio'] === '16:9' &&
               $data['resolution'] === '2k';
    });
});

it('can generate multiple images', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/image-1.png',
                ],
                [
                    'url' => 'https://example.com/image-2.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Abstract art')
        ->withProviderOptions([
            'n' => 2,
        ])
        ->generate();

    expect($response->imageCount())->toBe(2);
    expect($response->images[0]->url)->toBe('https://example.com/image-1.png');
    expect($response->images[1]->url)->toBe('https://example.com/image-2.png');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['n'] === 2;
    });
});

it('includes usage information in response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/usage-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 22,
                'completion_tokens' => 5,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Test usage tracking')
        ->generate();

    expect($response->usage->promptTokens)->toBe(22);
    expect($response->usage->completionTokens)->toBe(5);
});

it('includes meta information in response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'id' => 'img_abc123',
            'model' => 'grok-imagine-image',
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/meta-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Test meta information')
        ->generate();

    expect($response->meta->id)->toBe('img_abc123');
    expect($response->meta->model)->toBe('grok-imagine-image');
});

it('can generate an image using the Provider enum', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/enum-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using(Provider::XAI, 'grok-imagine-image')
        ->withPrompt('A blue circle')
        ->generate();

    expect($response->firstImage()->url)->toBe('https://example.com/enum-test.png');
});

it('extracts revised prompt from response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/revised-test.png',
                    'revised_prompt' => 'A highly detailed cute baby sea otter floating in calm water',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A cute baby sea otter')
        ->generate();

    expect($response->firstImage()->hasRevisedPrompt())->toBeTrue();
    expect($response->firstImage()->revisedPrompt)->toBe('A highly detailed cute baby sea otter floating in calm water');
});

it('handles response with both url and base64', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/both-test.png',
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUg==',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A test image')
        ->generate();

    expect($response->firstImage()->hasUrl())->toBeTrue();
    expect($response->firstImage()->hasBase64())->toBeTrue();
    expect($response->firstImage()->url)->toBe('https://example.com/both-test.png');
    expect($response->firstImage()->base64)->toBe('iVBORw0KGgoAAAANSUhEUg==');
});

it('does not send null provider options in request', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/null-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A simple test')
        ->generate();

    expect($response->firstImage())->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'grok-imagine-image' &&
               $data['prompt'] === 'A simple test' &&
               ! array_key_exists('n', $data) &&
               ! array_key_exists('response_format', $data) &&
               ! array_key_exists('aspect_ratio', $data) &&
               ! array_key_exists('resolution', $data);
    });
});

it('passes through unknown provider options', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [
                [
                    'url' => 'https://example.com/passthrough-test.png',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('A test image')
        ->withProviderOptions([
            'some_future_option' => 'value',
        ])
        ->generate();

    expect($response->firstImage())->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['some_future_option'] === 'value';
    });
});

it('throws exception on error response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid prompt',
            ],
        ], 200),
    ]);

    Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('bad prompt')
        ->generate();
})->throws(PrismException::class, 'XAI Error:  [invalid_request_error] Invalid prompt');

it('includes raw response data', function (): void {
    $rawResponse = [
        'created' => 1713833628,
        'data' => [
            [
                'url' => 'https://example.com/raw-test.png',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 0,
        ],
    ];

    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response($rawResponse, 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Test raw data')
        ->generate();

    expect($response->raw)->toBe($rawResponse);
});

it('handles empty data array in response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'created' => 1713833628,
            'data' => [],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
            ],
        ], 200),
    ]);

    $response = Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Empty result test')
        ->generate();

    expect($response->imageCount())->toBe(0);
    expect($response->firstImage())->toBeNull();
});

it('throws rate limited exception on 429 response', function (): void {
    Http::fake([
        'api.x.ai/v1/images/generations' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Prism::image()
        ->using('xai', 'grok-imagine-image')
        ->withPrompt('Rate limited test')
        ->generate();
})->throws(PrismRateLimitedException::class);
