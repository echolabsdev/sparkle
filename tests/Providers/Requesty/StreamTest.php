<?php

declare(strict_types=1);

namespace Tests\Providers\Requesty;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.requesty.api_key', env('REQUESTY_API_KEY', 'req-1234'));
});

it('excludes cached tokens from streamed promptTokens', function (): void {
    FixtureResponse::fakeStreamResponses('chat/completions', 'requesty/stream-cached-tokens');

    $response = Prism::text()
        ->using(Provider::Requesty, 'openai/gpt-4o')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $endEvent = null;

    foreach ($response as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }

        if ($event instanceof StreamEndEvent) {
            $endEvent = $event;
        }
    }

    expect($text)->toBe('Hello there')
        ->and($endEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($endEvent->usage->promptTokens)->toBe(40)
        ->and($endEvent->usage->cacheReadInputTokens)->toBe(60);
});
