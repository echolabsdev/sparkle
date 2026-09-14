<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched when a generation finishes successfully.
 *
 * `$usage`/`$finishReason` carry the terminal outcome for text/structured and
 * streaming paths; embeddings/images leave them null (read `$response->usage`).
 * `$response` is the full response object for non-streaming generations, or null
 * for streaming and when `prism.telemetry.capture_content` is disabled.
 *
 * `$usage` and `$rateLimits` are their OWN parameters, and both are populated
 * whatever `capture_content` says. That is deliberate and is the difference
 * between metadata and content: a token count and a quota bucket are numbers the
 * PROVIDER reported about the call, carrying nothing the user wrote, while
 * `$response` carries the completion itself. A listener commonly forwards this
 * event somewhere that leaves the application, so the split has to be made here
 * rather than left to each listener to get right.
 *
 * Do not move `$rateLimits` back under the content gate. Doing so was G-45: it
 * made quota headroom — the signal an operator needs BEFORE the 429, and the one
 * a 429 no longer helps with — depend on a privacy switch it has nothing to do
 * with, and one that is off by default.
 */
readonly class GenerationCompleted
{
    /**
     * @param  ProviderRateLimit[]  $rateLimits  The provider's quota buckets for
     *                                           this generation. Bucket name,
     *                                           limit, remaining and reset
     *                                           instant — no user content.
     */
    public function __construct(
        public TelemetryContext $context,
        public float $durationMs,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $response = null,
        public array $rateLimits = [],
    ) {}
}
