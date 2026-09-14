<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Generator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Neutral telemetry emission layer.
 *
 * Dispatches plain Laravel events across the generation lifecycle; consumers
 * (an OpenTelemetry bridge, Telescope, Pulse, custom listeners) subscribe. When
 * `prism.telemetry.enabled` is false every method is a no-op — no context is
 * minted, no event is dispatched.
 */
class Telemetry
{
    public static function enabled(): bool
    {
        return (bool) config('prism.telemetry.enabled', false);
    }

    public static function capturesContent(): bool
    {
        return (bool) config('prism.telemetry.capture_content', false);
    }

    public static function stack(): ContextStack
    {
        return app(ContextStack::class);
    }

    public static function current(): ?TelemetryContext
    {
        if (! self::enabled()) {
            return null;
        }

        return self::stack()->current();
    }

    public static function start(TelemetryOperation $operation, string $provider, string $model, mixed $request = null, ?string $userId = null, ?string $sessionId = null): ?TelemetryContext
    {
        if (! self::enabled()) {
            return null;
        }

        $context = new TelemetryContext(
            traceId: (string) Str::uuid(),
            operation: $operation,
            provider: $provider,
            model: $model,
            startedAt: microtime(true),
            userId: $userId,
            sessionId: $sessionId,
        );

        self::stack()->push($context);

        event(new GenerationStarted($context, self::capturesContent() ? $request : null));

        return $context;
    }

    public static function completed(?TelemetryContext $context, mixed $response = null, ?FinishReason $finishReason = null, ?Usage $usage = null): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        if ($response instanceof TextResponse || $response instanceof StructuredResponse) {
            $index = 0;

            foreach ($response->steps as $step) {
                event(new StepCompleted(
                    $context->withStep($index),
                    $step->finishReason,
                    $step->usage,
                    self::capturesContent() ? $step : null,
                    self::rateLimitsOf($step),
                ));

                $index++;
            }
        }

        event(new GenerationCompleted(
            $context,
            $context->elapsedMs(),
            $finishReason,
            $usage,
            self::capturesContent() ? $response : null,
            self::rateLimitsOf($response),
        ));
    }

    public static function failed(?TelemetryContext $context, Throwable $exception): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        event(new GenerationFailed($context, $context->elapsedMs(), $exception));
    }

    public static function end(?TelemetryContext $context): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        self::stack()->pop($context);
        self::stack()->clearStepFor($context->traceId);
    }

    /**
     * Advance the step cursor after a tool batch executes, so the next step's
     * tools are tagged with the correct step ordinal. A no-op when telemetry is
     * off (no active context).
     */
    public static function advanceStep(?TelemetryContext $context): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        self::stack()->advanceStepFor($context->traceId);
    }

    public static function toolInvoked(?TelemetryContext $context, ToolCall $toolCall, ToolResult $toolResult, float $durationMs, ?int $toolIndex = null): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        $capturesContent = self::capturesContent();

        // Tag the tool with the step that owns it (the current cursor) so a
        // consumer can nest tool spans under their step, then the tool ordinal.
        $eventContext = $context->withStep(self::stack()->stepFor($context->traceId));

        if ($toolIndex !== null) {
            $eventContext = $eventContext->withTool($toolIndex);
        }

        event(new ToolInvoked(
            $eventContext,
            $toolCall->name,
            $toolCall->id,
            $durationMs,
            $capturesContent ? $toolCall : null,
            $capturesContent ? $toolResult : null,
        ));
    }

    /**
     * Wrap a provider stream generator, emitting step and completion telemetry as
     * the uniform stream events flow through. Events pass through untouched; tool
     * spans are emitted separately from the tool-execution chokepoint.
     *
     * @param  Generator<StreamEvent>  $events
     * @return Generator<StreamEvent>
     */
    public static function instrumentStream(TelemetryContext $context, Generator $events, mixed $request = null): Generator
    {
        $capturesContent = self::capturesContent();
        $maxLength = max(0, (int) config('prism.telemetry.content_max_length', 65_536));
        $maxItems = max(0, (int) config('prism.telemetry.content_max_items', 256));
        $stepIndex = 0;
        $lastEnd = null;
        $stepText = '';
        $fullText = '';
        $toolCalls = [];
        $stepTruncated = false;
        $fullTruncated = false;
        $itemsTruncated = false;
        $systemPrompts = $capturesContent
            ? self::contentArray(is_object($request) && method_exists($request, 'systemPrompts') ? $request->systemPrompts() : [], $maxItems, $itemsTruncated)
            : [];
        $messages = $capturesContent
            ? self::contentArray(is_object($request) && method_exists($request, 'messages') ? $request->messages() : [], $maxItems, $itemsTruncated)
            : [];

        foreach ($events as $event) {
            if ($event instanceof TextDeltaEvent && $capturesContent) {
                self::appendBounded($stepText, $event->delta, $maxLength, $stepTruncated);
                self::appendBounded($fullText, $event->delta, $maxLength, $fullTruncated);
            } elseif ($event instanceof ToolCallEvent && $capturesContent) {
                self::appendItem($toolCalls, $event->toolCall->toArray(), $maxItems, $itemsTruncated);
            } elseif ($event instanceof ToolResultEvent && $capturesContent) {
                self::appendItem($messages, ['type' => 'tool_result', 'tool_results' => [$event->toolResult->toArray()]], $maxItems, $itemsTruncated);
            } elseif ($event instanceof StepFinishEvent) {
                $content = $capturesContent ? new StreamContent($stepText, $systemPrompts, $messages, $toolCalls, $stepTruncated || $itemsTruncated) : null;
                event(new StepCompleted($context->withStep($stepIndex), null, $event->usage, $content));

                if ($capturesContent) {
                    self::appendItem($messages, ['type' => 'assistant', 'content' => $stepText, 'tool_calls' => $toolCalls], $maxItems, $itemsTruncated);
                }

                $stepIndex++;
                $stepText = '';
                $toolCalls = [];
                $stepTruncated = false;
            } elseif ($event instanceof StreamEndEvent) {
                $lastEnd = $event;
            }

            yield $event;
        }

        self::completed($context, $capturesContent ? new StreamContent($fullText, $systemPrompts, $messages, truncated: $fullTruncated || $itemsTruncated) : null, $lastEnd?->finishReason, $lastEnd?->usage);
    }

    /**
     * The provider's quota buckets for a response or a step, read off its Meta.
     *
     * UNCONDITIONAL, and deliberately outside `capturesContent()`. A bucket is a
     * name, two integers and a reset instant, taken from a response header the
     * provider chose; there is nothing of the user's in it, and nothing of the
     * model's either. Content is what the gate exists for, and this is not
     * content.
     *
     * That distinction is the whole entry: while these travelled only on the
     * gated `$response`, a successful generation exported no quota at all under
     * the default config, so the numbers appeared exactly when a caller was
     * already rate limited (the 429 path carries them on the exception) and
     * never while there was still headroom to act on. See G-45.
     *
     * The filter is not defensive noise. `Meta::$rateLimits` is typed by
     * docblock alone, and this method is what makes the events' own
     * `ProviderRateLimit[]` a true statement rather than a second docblock.
     *
     * @return array<int, ProviderRateLimit>
     */
    protected static function rateLimitsOf(mixed $subject): array
    {
        if (! is_object($subject) || ! property_exists($subject, 'meta')) {
            return [];
        }

        $meta = $subject->meta;

        if (! $meta instanceof Meta) {
            return [];
        }

        return array_values(array_filter(
            $meta->rateLimits,
            // PHPStan reads `Meta::$rateLimits` as already typed and calls this
            // instanceof always-true. It is true of the docblock and not of the
            // runtime: PHP does not enforce an array's element type, and this
            // list is assembled by eighteen provider readers from raw headers.
            // The ignore is the point of the check, not an exception to it.
            /** @phpstan-ignore instanceof.alwaysTrue */
            fn (mixed $rateLimit): bool => $rateLimit instanceof ProviderRateLimit,
        ));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    protected static function contentArray(array $values, int $maxItems, bool &$truncated): array
    {
        if (count($values) > $maxItems) {
            $truncated = true;
        }

        $captureMedia = (bool) config('prism.telemetry.capture_media', false);

        return array_map(
            function (mixed $value) use ($captureMedia): array {
                $array = $value instanceof Arrayable
                    ? $value->toArray()
                    : (is_array($value) ? $value : ['type' => get_debug_type($value)]);

                // A message's stored form carries its attachments' bytes. See
                // MediaContent for why content capture withholds them by default.
                /** @var array<string, mixed> */
                return $captureMedia ? $array : MediaContent::withoutBytes($array);
            },
            array_slice($values, 0, $maxItems),
        );
    }

    protected static function appendBounded(string &$buffer, string $value, int $limit, bool &$truncated): void
    {
        $remaining = max(0, $limit - strlen($buffer));

        if (strlen($value) > $remaining) {
            $truncated = true;
        }

        if ($remaining > 0) {
            $buffer .= substr($value, 0, $remaining);
        }
    }

    /** @param array<int, mixed> $items */
    protected static function appendItem(array &$items, mixed $item, int $limit, bool &$truncated): void
    {
        if (count($items) >= $limit) {
            $truncated = true;

            return;
        }

        $items[] = $item;
    }
}
