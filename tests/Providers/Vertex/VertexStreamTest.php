<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertex.project_id', 'test-project');
    config()->set('prism.providers.vertex.region', 'us-central1');
    config()->set('prism.providers.vertex.access_token', 'test-access-token');
});

describe('Streaming for Vertex', function (): void {
    it('can stream text responses', function (): void {
        FixtureResponse::fakeStreamResponses('*', 'vertex/stream-basic');

        $events = [];
        $fullText = '';

        $stream = Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asStream();

        foreach ($stream as $event) {
            $events[] = $event;

            if ($event instanceof TextDeltaEvent) {
                $fullText .= $event->delta;
            }
        }

        // Should have stream start and end events
        expect(collect($events)->first())->toBeInstanceOf(StreamStartEvent::class);
        expect(collect($events)->last())->toBeInstanceOf(StreamEndEvent::class);

        // Should have text delta events
        $textDeltas = collect($events)->filter(fn ($e): bool => $e instanceof TextDeltaEvent);
        expect($textDeltas)->toHaveCount(3);

        // Full text should be concatenated correctly
        expect($fullText)->toBe('Hello, I am a helpful AI assistant.');

        // Stream end should have finish reason
        $endEvent = collect($events)->last();
        expect($endEvent->finishReason)->toBe(FinishReason::Stop);
    });

    it('sends streaming requests to the correct Vertex AI endpoint', function (): void {
        FixtureResponse::fakeStreamResponses('*', 'vertex/stream-basic');

        $stream = Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asStream();

        // Consume the stream
        foreach ($stream as $event) {
            // Just iterate to trigger the request
        }

        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toContain('us-central1-aiplatform.googleapis.com')
                ->and($request->url())->toContain('projects/test-project')
                ->and($request->url())->toContain('locations/us-central1')
                ->and($request->url())->toContain('publishers/google/models')
                ->and($request->url())->toContain('gemini-1.5-flash:streamGenerateContent')
                ->and($request->url())->toContain('alt=sse')
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->header('Authorization')[0])->toBe('Bearer test-access-token');

            return true;
        });
    });

    it('stream start event contains model and provider info', function (): void {
        FixtureResponse::fakeStreamResponses('*', 'vertex/stream-basic');

        $stream = Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asStream();

        $startEvent = null;
        foreach ($stream as $event) {
            if ($event instanceof StreamStartEvent) {
                $startEvent = $event;
                break;
            }
        }

        expect($startEvent)->not->toBeNull()
            ->and($startEvent->provider)->toBe('vertex')
            ->and($startEvent->model)->toBe('gemini-1.5-flash');
    });
});
