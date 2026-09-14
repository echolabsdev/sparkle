<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Prism\Prism\Enums\TelemetryOperation;

/**
 * Correlates every telemetry event emitted for a single generation.
 *
 * The stable {@see $traceId} plus the explicit {@see $stepIndex}/{@see $toolIndex}
 * ordinals let a consumer (e.g. an OpenTelemetry bridge) rebuild the span tree
 * deterministically, without relying on ambient context surviving the recursive
 * tool loop.
 */
readonly class TelemetryContext
{
    public function __construct(
        public string $traceId,
        public TelemetryOperation $operation,
        public string $provider,
        public string $model,
        public float $startedAt,
        public ?int $stepIndex = null,
        public ?int $toolIndex = null,
        public ?string $userId = null,
        public ?string $sessionId = null,
    ) {}

    public function withStep(int $stepIndex): self
    {
        return new self(
            $this->traceId,
            $this->operation,
            $this->provider,
            $this->model,
            $this->startedAt,
            $stepIndex,
            $this->toolIndex,
            $this->userId,
            $this->sessionId,
        );
    }

    public function withTool(int $toolIndex): self
    {
        return new self(
            $this->traceId,
            $this->operation,
            $this->provider,
            $this->model,
            $this->startedAt,
            $this->stepIndex,
            $toolIndex,
            $this->userId,
            $this->sessionId,
        );
    }

    /**
     * Milliseconds elapsed since this context was started.
     */
    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
