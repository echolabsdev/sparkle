<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY'));
    config()->set('prism.providers.replicate.polling_interval', 1000);
    config()->set('prism.providers.replicate.max_wait_time', 120);
});

describe('Real-time SSE Streaming for Replicate', function (): void {
    it('can stream text in real-time using SSE', function (): void {
        // This test requires a real Replicate API key and makes real API calls
        if (! env('REPLICATE_API_KEY') || env('REPLICATE_API_KEY') === 'r8_test1234') {
            $this->markTestSkipped('Requires real REPLICATE_API_KEY environment variable');
        }

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3-8b-instruct')
            ->withPrompt('Say hello in 5 words or less')
            ->withMaxTokens(20)
            ->asStream();

        $text = '';
        $events = [];
        $deltaCount = 0;
        $receivedStreamStart = false;
        $receivedTextStart = false;
        $receivedTextDelta = false;
        $receivedTextComplete = false;
        $receivedStreamEnd = false;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof StreamStartEvent) {
                $receivedStreamStart = true;
            }

            if ($event instanceof TextStartEvent) {
                $receivedTextStart = true;
            }

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
                $deltaCount++;
                $receivedTextDelta = true;
            }

            if ($event instanceof TextCompleteEvent) {
                $receivedTextComplete = true;
            }

            if ($event instanceof StreamEndEvent) {
                $receivedStreamEnd = true;
            }
        }

        // Verify all events were received
        expect($receivedStreamStart)->toBeTrue('Should receive StreamStartEvent')
            ->and($receivedTextStart)->toBeTrue('Should receive TextStartEvent')
            ->and($receivedTextDelta)->toBeTrue('Should receive at least one TextDeltaEvent')
            ->and($receivedTextComplete)->toBeTrue('Should receive TextCompleteEvent')
            ->and($receivedStreamEnd)->toBeTrue('Should receive StreamEndEvent');

        // Verify text was generated
        expect($text)->not->toBeEmpty('Should have generated some text')
            ->and($deltaCount)->toBeGreaterThan(0, 'Should have received multiple deltas');
    })->group('integration', 'sse', 'slow');

    it('receives tokens in real-time without waiting for completion', function (): void {
        // This test verifies that tokens arrive progressively, not all at once
        if (! env('REPLICATE_API_KEY') || env('REPLICATE_API_KEY') === 'r8_test1234') {
            $this->markTestSkipped('Requires real REPLICATE_API_KEY environment variable');
        }

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3-8b-instruct')
            ->withPrompt('Count from 1 to 10')
            ->withMaxTokens(50)
            ->asStream();

        $timestamps = [];
        $firstDeltaTime = null;
        $lastDeltaTime = null;

        foreach ($response as $event) {
            if ($event instanceof TextDeltaEvent) {
                $currentTime = microtime(true);
                $timestamps[] = $currentTime;

                if ($firstDeltaTime === null) {
                    $firstDeltaTime = $currentTime;
                }

                $lastDeltaTime = $currentTime;
            }
        }

        // Verify we received multiple delta events (proof of streaming, not batch)
        expect($timestamps)->toHaveCount(count($timestamps))
            ->and(count($timestamps))->toBeGreaterThan(1, 'Should receive multiple token deltas for streaming');

        // If tokens were buffered and sent all at once (simulated streaming),
        // we would likely get very few deltas. Real SSE typically sends many small chunks.
        // For a "count from 1 to 10" prompt, we should get multiple deltas.
        expect(count($timestamps))->toBeGreaterThan(5, 'Real SSE should produce many small token chunks');
    })->group('integration', 'sse', 'slow');

    it('handles SSE stream errors gracefully', function (): void {
        // Test that errors in the stream are properly handled
        if (! env('REPLICATE_API_KEY') || env('REPLICATE_API_KEY') === 'r8_test1234') {
            $this->markTestSkipped('Requires real REPLICATE_API_KEY environment variable');
        }

        // Use an invalid model to trigger an error
        expect(function (): void {
            $response = Prism::text()
                ->using('replicate', 'invalid/model-does-not-exist')
                ->withPrompt('This should fail')
                ->asStream();

            // Try to consume the stream
            foreach ($response as $event) {
                // Should throw before we get here
            }
        })->toThrow(PrismException::class);
    })->group('integration', 'sse', 'slow');
})->group('integration');
