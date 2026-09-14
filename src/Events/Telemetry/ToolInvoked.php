<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Dispatched after a single tool call executes. `$durationMs` is measured around
 * the tool handler itself (accurate for concurrently-executed tools); the tool
 * ordinal lives on `$context->toolIndex`.
 *
 * `$toolName` and `$toolCallId` are always present — they are identity/metric
 * fields safe for spans. The content-bearing `$toolCall` (arguments) and
 * `$toolResult` (args + result) are null unless `prism.telemetry.capture_content`
 * is enabled, since they can carry user PII.
 */
readonly class ToolInvoked
{
    public function __construct(
        public TelemetryContext $context,
        public string $toolName,
        public string $toolCallId,
        public float $durationMs,
        public ?ToolCall $toolCall = null,
        public ?ToolResult $toolResult = null,
    ) {}
}
