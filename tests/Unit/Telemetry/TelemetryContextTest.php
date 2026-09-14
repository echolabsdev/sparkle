<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\TelemetryContext;

it('carries step and tool ordinals immutably', function (): void {
    $base = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'gpt-x', microtime(true));

    expect($base->stepIndex)->toBeNull();
    expect($base->toolIndex)->toBeNull();

    $stepped = $base->withStep(2);

    expect($stepped->stepIndex)->toBe(2);
    expect($stepped->traceId)->toBe('trace');
    expect($base->stepIndex)->toBeNull(); // original untouched

    $tooled = $stepped->withTool(5);

    expect($tooled->toolIndex)->toBe(5);
    expect($tooled->stepIndex)->toBe(2);
    expect($tooled->traceId)->toBe('trace');
});

it('measures elapsed milliseconds from the start time', function (): void {
    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'gpt-x', microtime(true) - 0.05);

    expect($context->elapsedMs())->toBeGreaterThanOrEqual(50.0);
});
