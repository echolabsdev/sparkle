<?php

declare(strict_types=1);

use Prism\Prism\Audio\PendingRequest;
use Prism\Prism\Audio\ProviderIdResponse;
use Prism\Prism\Audio\SpeechToTextAsyncRequest;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Providers\Provider as ProviderContract;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\TestDoubles\TestProvider;

beforeEach(function (): void {
    $this->pendingRequest = new PendingRequest;
});

test('it generates a provider id response for speech to text', function (): void {
    resolve('prism-manager')->extend('test-provider', fn ($config): ProviderContract => new TestProvider);

    $audio = Audio::fromUrl('https://example.com/audio.mp3', 'audio/mpeg');

    $response = $this->pendingRequest
        ->using('test-provider', 'test-model')
        ->withInput($audio)
        ->asTextProviderId();

    $provider = $this->pendingRequest->provider();

    expect($response)
        ->toBeInstanceOf(ProviderIdResponse::class)
        ->and($response->id)->toBe('provider-id')
        ->and($provider->request)->toBeInstanceOf(SpeechToTextRequest::class)
        ->and($provider->request->input())->toBe($audio);
});

test('it generates a response for async speech to text', function (): void {
    resolve('prism-manager')->extend('test-provider', fn ($config): ProviderContract => new TestProvider);

    $providerId = 'provider-id-123';

    $response = $this->pendingRequest
        ->using('test-provider', 'test-model')
        ->withInput($providerId)
        ->asTextAsync();

    $provider = $this->pendingRequest->provider();

    expect($response->text)->toBe('Async transcript')
        ->and($provider->request)->toBeInstanceOf(SpeechToTextAsyncRequest::class)
        ->and($provider->request->model())->toBe('test-model')
        ->and($provider->request->input())->toBe($providerId);
});
