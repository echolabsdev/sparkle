<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/agent', 'perplexity/agent-stream-basic-text');

    $response = Prism::text()
        ->using(Provider::Perplexity, 'sonar')
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

    expect($events)->not->toBeEmpty();

    /**
     * @var StreamEndEvent $lastEvent
     */
    $lastEvent = end($events);

    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
        ->and($lastEvent->usage)->not->toBeNull()
        ->and($lastEvent->usage->promptTokens)->toBe(4)
        ->and($lastEvent->usage->completionTokens)->toBe(97)
        ->and($text)->not->toBeEmpty();

    Http::assertSent(static function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});
