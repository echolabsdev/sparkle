<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Image Generation for Replicate', function (): void {
    it('can generate an image with flux-schnell', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-2.json'), true);
        $predictionId = $createResponse['id'];
        $imageUrl = $completedResponse['output'][0];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $imageUrl => Http::response('fake-image-content', 200),
        ]);

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
            ->generate();

        expect($response->firstImage())->not->toBeNull()
            ->and($response->firstImage()->hasUrl())->toBeTrue()
            ->and($response->firstImage()->url)->not->toBeEmpty()
            ->and($response->firstImage()->hasBase64())->toBeTrue()
            ->and($response->firstImage()->base64)->not->toBeEmpty()
            ->and($response->imageCount())->toBe(1);
    });

    it('can generate an image with provider options', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-image-with-options');

        Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A mountain landscape at sunset')
            ->withProviderOptions([
                'aspect_ratio' => '16:9',
                'output_format' => 'png',
            ])
            ->generate();

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), 'predictions')) {
                return false;
            }

            $body = json_decode((string) $request->body(), true);

            return isset($body['input']['prompt'])
                && $body['input']['prompt'] === 'A mountain landscape at sunset'
                && isset($body['input']['aspect_ratio'])
                && $body['input']['aspect_ratio'] === '16:9';
        });
    });

    it('includes meta information in response', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-2.json'), true);
        $predictionId = $createResponse['id'];
        $imageUrl = $completedResponse['output'][0];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $imageUrl => Http::response('fake-image-content', 200),
        ]);

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
            ->generate();

        expect($response->meta->id)->not->toBeEmpty()
            ->and($response->meta->model)->toBe('black-forest-labs/flux-schnell');
    });
});

describe('Image download hardening', function (): void {
    function fakeReplicatePrediction(string $imageUrl): void
    {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/generate-image-basic-2.json'), true);
        // Override the output URL in both responses — sync mode uses the
        // create response directly.
        if (isset($createResponse['output'])) {
            $createResponse['output'] = [$imageUrl];
        }
        $completedResponse['output'] = [$imageUrl];
        $predictionId = $createResponse['id'];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            '*' => Http::response('fake-image-content', 200),
        ]);
    }

    it('does not download from non-https urls', function (): void {
        fakeReplicatePrediction('http://replicate.delivery/pbxt/image.webp');

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('otter')
            ->generate();

        expect($response->firstImage()->base64)->toBeNull()
            ->and($response->firstImage()->url)->toBe('http://replicate.delivery/pbxt/image.webp');

        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'pbxt/image.webp'));
    });

    it('does not download from hosts outside the allowlist', function (): void {
        fakeReplicatePrediction('https://169.254.169.254/latest/meta-data');

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('otter')
            ->generate();

        expect($response->firstImage()->base64)->toBeNull();

        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254.169.254'));
    });

    it('downloads from allowlisted subdomains over https', function (): void {
        fakeReplicatePrediction('https://cdn.replicate.delivery/pbxt/image.webp');

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('otter')
            ->generate();

        expect($response->firstImage()->base64)->not->toBeNull()
            ->and(base64_decode((string) $response->firstImage()->base64))->toBe('fake-image-content');
    });

    it('honors a custom download host allowlist', function (): void {
        config()->set('prism.providers.replicate.download_hosts', ['my-gateway.example.com']);

        fakeReplicatePrediction('https://my-gateway.example.com/outputs/image.webp');

        $response = Prism::image()
            ->using('replicate', 'black-forest-labs/flux-schnell')
            ->withPrompt('otter')
            ->generate();

        expect($response->firstImage()->base64)->not->toBeNull();
    });
});
