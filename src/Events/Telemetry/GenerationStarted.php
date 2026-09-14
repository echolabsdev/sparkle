<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;

/**
 * Dispatched when a generation begins, before the provider request is sent.
 *
 * @property-read mixed $request The originating request, or null when
 *                               `prism.telemetry.capture_content` is disabled.
 */
readonly class GenerationStarted
{
    public function __construct(
        public TelemetryContext $context,
        public mixed $request = null,
    ) {}
}
