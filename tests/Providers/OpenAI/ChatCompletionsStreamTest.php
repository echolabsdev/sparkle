<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-test-1234'));
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

describe('Streaming for OpenAI chat/completions', function (): void {
    it('can generate text with a basic stream', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-basic-text');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->asStream();

        $text = '';
        $events = [];

        foreach ($response as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        expect($events)
            ->not->toBeEmpty()
            ->and($text)->not->toBeEmpty()
            ->and($text)->toContain("Hello! I'm an AI assistant. How can I help?");

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('stream end event has non-null usage', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-basic-text');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->asStream();

        $lastEvent = null;
        foreach ($response as $event) {
            $lastEvent = $event;
        }

        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->usage)->not->toBeNull();
        expect($lastEvent->usage->promptTokens)->toBe(10);
        expect($lastEvent->usage->completionTokens)->toBe(13);
    });
});
