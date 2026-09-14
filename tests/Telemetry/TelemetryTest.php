<?php

declare(strict_types=1);

namespace Tests\Telemetry;

use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

beforeEach(function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
});

it('emits nothing when telemetry is disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);

    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('hi')]);

    Prism::text()->using('anthropic', 'claude-3')->withPrompt('q')->asText();

    Event::assertNotDispatched(GenerationStarted::class);
    Event::assertNotDispatched(GenerationCompleted::class);
});

it('emits started and completed around a successful text generation', function (): void {
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('The meaning of life is 42')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(42, 7)),
    ]);

    Prism::text()->using('anthropic', 'claude-3-sonnet')->withPrompt('q')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->context->operation === TelemetryOperation::Text
        && $e->context->provider === 'anthropic'
        && $e->context->model === 'claude-3-sonnet'
        && $e->context->traceId !== '');

    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->finishReason === FinishReason::Stop
        && $e->usage?->promptTokens === 42
        && $e->usage?->completionTokens === 7
        && $e->durationMs >= 0.0);
});

it('omits request content unless capture_content is enabled', function (): void {
    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('x')]);

    Prism::text()->using('anthropic', 'm')->withPrompt('secret prompt')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->request === null);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->response === null);
});

it('includes request and response content when capture_content is enabled', function (): void {
    config()->set('prism.telemetry.capture_content', true);

    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('x')]);

    Prism::text()->using('anthropic', 'm')->withPrompt('secret prompt')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->request !== null);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->response !== null);
});

it('carries the provider rate limits with capture_content OFF, which is the default', function (): void {
    // G-45. Quota headroom is not content: a bucket is a name, two integers and
    // a reset instant, all of them read off a response header the provider
    // chose. While they travelled only on the gated `$response`, a SUCCESSFUL
    // generation exported none of them under the default config -- so an
    // operator saw the numbers only once a 429 had already happened (that path
    // carries them on the exception) and never while there was headroom left to
    // act on.
    //
    // Asserted together with `$response === null` on purpose: the point is not
    // that rate limits arrive, it is that they arrive WHILE THE CONTENT GATE IS
    // SHUT. A version of this test that enabled capture would pass against the
    // defect.
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('x')
            ->withMeta(new Meta('id', 'm', rateLimits: [
                new ProviderRateLimit('requests', limit: 1000, remaining: 999, resetsAt: Carbon::createFromTimestamp(1788611696)),
                new ProviderRateLimit('tokens', limit: 40000, remaining: 39000),
            ])),
    ]);

    Prism::text()->using('anthropic', 'm')->withPrompt('secret prompt')->asText();

    Event::assertDispatched(GenerationCompleted::class, function (GenerationCompleted $e): bool {
        expect($e->response)->toBeNull()
            ->and($e->rateLimits)->toHaveCount(2)
            ->and($e->rateLimits[0]->name)->toBe('requests')
            ->and($e->rateLimits[0]->limit)->toBe(1000)
            ->and($e->rateLimits[0]->remaining)->toBe(999)
            ->and($e->rateLimits[0]->resetsAt?->getTimestamp())->toBe(1788611696)
            ->and($e->rateLimits[1]->name)->toBe('tokens');

        return true;
    });
});

it('carries no rate limits when the provider reported none', function (): void {
    // Absent and present-and-empty have to stay different downstream: a
    // consumer that writes an empty bucket list claims it asked and was told
    // nothing. Most providers report no quota headers at all.
    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('x')]);

    Prism::text()->using('anthropic', 'm')->withPrompt('q')->asText();

    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->rateLimits === []);
});

it('carries the rate limits of each STEP, also with capture off', function (): void {
    // The terminal response's Meta describes the LAST provider call. In a tool
    // loop the per-step buckets are what show quota being spent across the run,
    // and they were gated by the same switch for the same wrong reason.
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('done')
            ->withSteps(collect([
                TextStepFake::make()
                    ->withUsage(new Usage(1, 1))
                    ->withMeta(new Meta('s1', 'm', rateLimits: [new ProviderRateLimit('requests', limit: 10, remaining: 9)])),
                TextStepFake::make()
                    ->withUsage(new Usage(2, 2))
                    ->withMeta(new Meta('s2', 'm', rateLimits: [new ProviderRateLimit('requests', limit: 10, remaining: 8)])),
            ])),
    ]);

    Prism::text()->using('anthropic', 'm')->withPrompt('q')->asText();

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $e): bool => $e->context->stepIndex === 0
        && $e->step === null
        && count($e->rateLimits) === 1
        && $e->rateLimits[0]->remaining === 9);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $e): bool => $e->context->stepIndex === 1
        && $e->step === null
        && count($e->rateLimits) === 1
        && $e->rateLimits[0]->remaining === 8);
});

it('drops anything in Meta::$rateLimits that is not a ProviderRateLimit', function (): void {
    // `Meta::$rateLimits` is typed by docblock alone, and a docblock is not a
    // check. The events declare `ProviderRateLimit[]`, so the filter is what
    // makes that a true statement instead of a second docblock -- a listener
    // reading `->name` on whatever arrived would fatal inside the dispatcher.
    Event::fake();

    $meta = new Meta('id', 'm', rateLimits: [
        new ProviderRateLimit('requests', limit: 5),
        ['name' => 'tokens', 'limit' => 5],
        'tokens',
        null,
    ]);

    Prism::fake([TextResponseFake::make()->withText('x')->withMeta($meta)]);

    Prism::text()->using('anthropic', 'm')->withPrompt('q')->asText();

    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => count($e->rateLimits) === 1
        && $e->rateLimits[0]->name === 'requests');
});

it('emits a StepCompleted event per response step', function (): void {
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('done')
            ->withSteps(collect([
                TextStepFake::make()->withUsage(new Usage(1, 1)),
                TextStepFake::make()->withUsage(new Usage(2, 2)),
            ])),
    ]);

    Prism::text()->using('anthropic', 'm')->withPrompt('q')->asText();

    Event::assertDispatchedTimes(StepCompleted::class, 2);
    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $e): bool => $e->context->stepIndex === 1);
});

it('start returns null and dispatches nothing when disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);

    Event::fake();

    expect(Telemetry::start(TelemetryOperation::Text, 'openai', 'm'))->toBeNull();

    Event::assertNotDispatched(GenerationStarted::class);
});

it('emits GenerationFailed for a failed generation', function (): void {
    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::failed($context, new RuntimeException('boom'));

    Event::assertDispatched(GenerationFailed::class, fn (GenerationFailed $e): bool => $e->exception->getMessage() === 'boom'
        && $e->context->traceId === 'trace'
        && $e->durationMs >= 0.0);
});

it('instruments a stream: passes events through and emits step + completion telemetry', function (): void {
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    expect($context)->not->toBeNull();

    $inner = (function (): Generator {
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(3, 4));
    })();

    $events = iterator_to_array(Telemetry::instrumentStream($context, $inner), false);

    Telemetry::end($context);

    expect($events)->toHaveCount(2);

    Event::assertDispatchedTimes(StepCompleted::class, 1);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->finishReason === FinishReason::Stop
        && $e->usage?->completionTokens === 4);
});

it('captures streaming step and final output content when enabled', function (): void {
    config()->set('prism.telemetry.capture_content', true);
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    $inner = (function (): Generator {
        yield new TextDeltaEvent('d1', time(), 'Hello ', 'm1');
        yield new TextDeltaEvent('d2', time(), 'world', 'm1');
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(1, 2));
    })();

    iterator_to_array(Telemetry::instrumentStream($context, $inner), false);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->step?->toArray()['text'] === 'Hello world');
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $event): bool => $event->response?->toArray()['text'] === 'Hello world');
});

it('bounds captured streaming content and reports truncation', function (): void {
    config()->set('prism.telemetry.capture_content', true);
    config()->set('prism.telemetry.content_max_length', 5);
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    $inner = (function (): Generator {
        yield new TextDeltaEvent('d1', time(), 'sensitive-long-output', 'm1');
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(1, 2));
    })();

    iterator_to_array(Telemetry::instrumentStream($context, $inner), false);

    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $event): bool => $event->response?->toArray()['text'] === 'sensi'
        && $event->response?->toArray()['truncated'] === true);
});

it('does not reconstruct stream content when capture is disabled', function (): void {
    Event::fake();
    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    $inner = (function (): Generator {
        yield new TextDeltaEvent('d1', time(), str_repeat('x', 100_000), 'm1');
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(1, 2));
    })();

    iterator_to_array(Telemetry::instrumentStream($context, $inner), false);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->step === null);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $event): bool => $event->response === null);
});

it('does not expose private properties from unknown message objects', function (): void {
    config()->set('prism.telemetry.capture_content', true);
    Event::fake();

    $request = new class
    {
        public function systemPrompts(): array
        {
            return [];
        }

        public function messages(): array
        {
            return [new class
            {
                private string $secret = 'must-not-leak';

                public function secret(): string
                {
                    return $this->secret;
                }
            }];
        }
    };
    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    $inner = (function (): Generator {
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(1, 2));
    })();

    iterator_to_array(Telemetry::instrumentStream($context, $inner, $request), false);

    Event::assertDispatched(StepCompleted::class, function (StepCompleted $event): bool {
        $json = json_encode($event->step?->toArray(), JSON_THROW_ON_ERROR);

        return ! str_contains($json, 'must-not-leak') && str_contains($json, '"type"');
    });
});

it('carries user and session ids on the telemetry context', function (): void {
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Text, 'openai', 'gpt-x', userId: 'user-7', sessionId: 'session-9');

    expect($context?->userId)->toBe('user-7')
        ->and($context?->sessionId)->toBe('session-9')
        ->and($context?->withStep(1)->userId)->toBe('user-7')
        ->and($context?->withTool(2)->sessionId)->toBe('session-9');
});

it('emits ToolInvoked with identity + duration and withholds content by default', function (): void {
    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::toolInvoked(
        $context,
        new ToolCall('call-1', 'weather', ['city' => 'NYC']),
        new ToolResult(toolCallId: 'call-1', toolName: 'weather', args: ['city' => 'NYC'], result: 'Sunny'),
        12.5,
        0,
    );

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->context->toolIndex === 0
        && $e->durationMs === 12.5
        && $e->toolName === 'weather'
        && $e->toolCallId === 'call-1'
        // content withheld because capture_content is off (the default)
        && ! $e->toolCall instanceof ToolCall
        && ! $e->toolResult instanceof ToolResult);
});

it('includes tool call and result content only when capture_content is enabled', function (): void {
    config()->set('prism.telemetry.capture_content', true);

    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::toolInvoked(
        $context,
        new ToolCall('call-1', 'weather', ['city' => 'NYC']),
        new ToolResult(toolCallId: 'call-1', toolName: 'weather', args: ['city' => 'NYC'], result: 'Sunny'),
        12.5,
        0,
    );

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->toolName === 'weather'
        && $e->toolCall?->name === 'weather'
        && $e->toolResult?->result === 'Sunny');
});

it('tags tool events with the advancing step cursor so tools nest under their step', function (): void {
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Text, 'openai', 'gpt-4o');

    $invoke = function (string $id) use ($context): void {
        Telemetry::toolInvoked(
            $context,
            new ToolCall($id, 'weather', []),
            new ToolResult(toolCallId: $id, toolName: 'weather', args: [], result: 'ok'),
            1.0,
            0,
        );
    };

    $invoke('c0');                     // step 0's tool
    Telemetry::advanceStep($context);  // batch boundary — next tools are step 1
    $invoke('c1');                     // step 1's tool

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->toolCallId === 'c0' && $e->context->stepIndex === 0);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->toolCallId === 'c1' && $e->context->stepIndex === 1);

    // ending the generation clears its cursor
    Telemetry::end($context);
    expect(Telemetry::stack()->stepFor($context->traceId))->toBe(0);
});

describe('media inside captured streaming content', function (): void {
    // capture_content was understood to export TEXT. A message's stored form
    // carries each attachment's bytes, so without this a captured stream carried
    // the user's uploaded file to every telemetry listener.

    function capturedMessages(): array
    {
        Event::fake();

        $request = new class
        {
            /** @return list<UserMessage> */
            public function messages(): array
            {
                return [new UserMessage('What is in this?', [Image::fromRawContent('SECRET-FILE-BYTES', 'image/png')])];
            }
        };

        $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
        $inner = (function (): Generator {
            yield new TextDeltaEvent('d1', time(), 'seen', 'm1');
            yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(1, 2));
        })();

        iterator_to_array(Telemetry::instrumentStream($context, $inner, $request), false);

        $captured = null;
        Event::assertDispatched(GenerationCompleted::class, function (GenerationCompleted $event) use (&$captured): bool {
            $captured = $event->response?->toArray()['messages'] ?? null;

            return true;
        });

        return $captured ?? [];
    }

    it('withholds the bytes by default, and records their size', function (): void {
        config()->set('prism.telemetry.capture_content', true);

        $image = capturedMessages()[0]['additional_content'][0];

        expect($image['kind'])->toBe('image')
            ->and($image['mime_type'])->toBe('image/png')
            ->and($image['base64'])->toBeNull()
            ->and($image['omitted_bytes'])->toBe(17)
            ->and(json_encode(capturedMessages()))->not->toContain(base64_encode('SECRET-FILE-BYTES'));
    });

    it('keeps the bytes when capture_media is on', function (): void {
        config()->set('prism.telemetry.capture_content', true);
        config()->set('prism.telemetry.capture_media', true);

        $image = capturedMessages()[0]['additional_content'][0];

        expect($image['base64'])->toBe(base64_encode('SECRET-FILE-BYTES'))
            ->and($image)->not->toHaveKey('omitted_bytes');
    });
});
