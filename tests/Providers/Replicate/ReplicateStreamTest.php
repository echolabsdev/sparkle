<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Streaming for Replicate', function (): void {
    it('can generate text with a basic stream', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello, world!')
            ->asStream();

        $text = '';
        $events = [];
        $deltaCount = 0;

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
                $deltaCount++;
            }
        }

        expect($events)->not->toBeEmpty()
            ->and($text)->not->toBeEmpty()
            ->and($deltaCount)->toBeGreaterThan(0);
    });

    it('emits all expected stream events', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello, world!')
            ->asStream();

        $hasStreamStart = false;
        $hasTextStart = false;
        $hasTextDelta = false;
        $hasTextComplete = false;
        $hasStreamEnd = false;

        foreach ($response as $event) {
            if ($event instanceof StreamStartEvent) {
                $hasStreamStart = true;
            }
            if ($event instanceof TextStartEvent) {
                $hasTextStart = true;
            }
            if ($event instanceof TextDeltaEvent) {
                $hasTextDelta = true;
            }
            if ($event instanceof TextCompleteEvent) {
                $hasTextComplete = true;
            }
            if ($event instanceof StreamEndEvent) {
                $hasStreamEnd = true;
            }
        }

        expect($hasStreamStart)->toBeTrue()
            ->and($hasTextStart)->toBeTrue()
            ->and($hasTextDelta)->toBeTrue()
            ->and($hasTextComplete)->toBeTrue()
            ->and($hasStreamEnd)->toBeTrue();
    });

    it('includes usage information in stream end event', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello, world!')
            ->asStream();

        $streamEndEvent = null;

        foreach ($response as $event) {
            if ($event instanceof StreamEndEvent) {
                $streamEndEvent = $event;
            }
        }

        expect($streamEndEvent)->not->toBeNull()
            ->and($streamEndEvent->usage->promptTokens)->toBeGreaterThan(0)
            ->and($streamEndEvent->usage->completionTokens)->toBeGreaterThan(0);
    });

    it('reconstructs full text from deltas', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withSystemPrompt('You are a helpful assistant')
            ->withPrompt('Say hello')
            ->asStream();

        $streamedText = '';

        foreach ($response as $event) {
            if ($event instanceof TextDeltaEvent) {
                $streamedText .= $event->delta;
            }
        }

        expect($streamedText)->not->toBeEmpty();
    });
});
