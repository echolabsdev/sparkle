<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched once per step of a multi-step generation. The step ordinal lives on
 * `$context->stepIndex`. `$step` is the full step object for non-streaming
 * generations (when content capture is enabled), null for the streaming path
 * where only usage is known at the step boundary.
 *
 * `$usage` and `$rateLimits` are their own parameters, populated whatever
 * `prism.telemetry.capture_content` says, for the reason spelled out on
 * {@see GenerationCompleted}: they are numbers the provider reported about the
 * call and carry nothing the user wrote, where `$step` carries the completion.
 *
 * A step's buckets are the headers of THAT provider call. In a tool loop they
 * are what shows quota being spent across the run — the terminal response's
 * `Meta` only ever describes the last call.
 */
readonly class StepCompleted
{
    /**
     * @param  ProviderRateLimit[]  $rateLimits  The provider's quota buckets for
     *                                           this step. No user content.
     */
    public function __construct(
        public TelemetryContext $context,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $step = null,
        public array $rateLimits = [],
    ) {}
}
