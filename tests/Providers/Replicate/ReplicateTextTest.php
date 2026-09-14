<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10); // Fast polling for tests
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Text generation for Replicate', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello, world!')
            ->withMaxTokens(100)
            ->generate();

        expect($response->text)->toContain('Hello')
            ->and($response->steps[0]->additionalContent['metrics']['predict_time'])->toBeGreaterThan(0)
            ->and($response->steps[0]->additionalContent['metrics']['total_time'])->toBeGreaterThan(0);
    });

    it('can generate text with system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->text)->toContain('Hello');
    });

    it('handles model with version specified', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-version');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct:v1')
            ->withPrompt('Hello!')
            ->generate();

        expect($response->text)->toContain('Hello');
    });
});
