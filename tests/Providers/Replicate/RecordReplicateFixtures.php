<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    $apiKey = env('REPLICATE_API_KEY');
    if (! $apiKey || str_starts_with((string) $apiKey, 'r8_test')) {
        $this->markTestSkipped('Real REPLICATE_API_KEY not configured');
    }
    config()->set('prism.providers.replicate.api_key', $apiKey);
    config()->set('prism.providers.replicate.polling_interval', 1000); // 1 second
    config()->set('prism.providers.replicate.max_wait_time', 120); // 2 minutes
});

describe('Record Replicate Fixtures', function (): void {
    it('records a real text generation response', function (): void {
        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-8b-instruct')
            ->withPrompt('Hello, world!')
            ->withMaxTokens(50)
            ->generate();

        expect($response->text)->not->toBeEmpty()
            ->and($response->steps)->toHaveCount(1)
            ->and($response->steps[0]->meta->id)->not->toBeEmpty();

        // Output for manual verification
        dump([
            'text' => $response->text,
            'meta' => [
                'id' => $response->steps[0]->meta->id,
                'model' => $response->steps[0]->meta->model,
            ],
            'metrics' => $response->steps[0]->additionalContent['metrics'] ?? null,
        ]);
    })->skip('Run manually with: ./vendor/bin/pest tests/Providers/Replicate/RecordReplicateFixtures.php');

    it('records text generation with system prompt', function (): void {
        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-8b-instruct')
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Who are you?')
            ->withMaxTokens(50)
            ->generate();

        expect($response->text)->not->toBeEmpty();

        dump([
            'text' => $response->text,
        ]);
    })->skip('Run manually with: ./vendor/bin/pest tests/Providers/Replicate/RecordReplicateFixtures.php');
});
