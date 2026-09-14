<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

/**
 * Ambient stack of active telemetry contexts for the current process.
 *
 * Bound as a container singleton so tool-execution chokepoints can discover the
 * generation they run inside without threading the context through every handler
 * signature. Nested generations push/pop in LIFO order; {@see pop()} tolerates
 * out-of-order removal so an abandoned streaming generator cannot corrupt the
 * stack for later calls.
 */
class ContextStack
{
    /** @var list<TelemetryContext> */
    protected array $stack = [];

    /**
     * Per-generation step cursor, keyed by trace id. Advanced once per executed
     * tool batch so tool events can be tagged with the step that owns them —
     * Prism's recursive loop runs step N's tools before step N is recorded.
     *
     * @var array<string, int>
     */
    protected array $stepCursors = [];

    public function push(TelemetryContext $context): void
    {
        $this->stack[] = $context;
        $this->stepCursors[$context->traceId] ??= 0;
    }

    /**
     * The current step ordinal for a generation (0 before any tool batch runs).
     */
    public function stepFor(string $traceId): int
    {
        return $this->stepCursors[$traceId] ?? 0;
    }

    /**
     * Advance a generation's step cursor after a tool batch completes.
     */
    public function advanceStepFor(string $traceId): void
    {
        $this->stepCursors[$traceId] = ($this->stepCursors[$traceId] ?? 0) + 1;
    }

    public function clearStepFor(string $traceId): void
    {
        unset($this->stepCursors[$traceId]);
    }

    public function current(): ?TelemetryContext
    {
        $key = array_key_last($this->stack);

        return $key === null ? null : $this->stack[$key];
    }

    public function pop(?TelemetryContext $context = null): ?TelemetryContext
    {
        $key = array_key_last($this->stack);

        if ($key === null) {
            return null;
        }

        if ($context instanceof TelemetryContext && $this->stack[$key] !== $context) {
            foreach (array_reverse(array_keys($this->stack)) as $index) {
                if ($this->stack[$index] === $context) {
                    array_splice($this->stack, $index, 1);

                    return $context;
                }
            }

            return null;
        }

        return array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
        $this->stepCursors = [];
    }
}
