<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HasReasoning
{
    protected ?bool $reasoningEnabled = null;

    /**
     * Toggle reasoning / thinking output on or off in a provider-agnostic way.
     *
     * `withReasoning(false)` instructs the provider to skip reasoning when it
     * supports doing so (e.g. Ollama `think: false`, OpenAI `reasoning.effort
     * = minimal`). Providers that cannot disable reasoning treat the call as a
     * graceful no-op.
     *
     * Calling `withReasoning(true)` is reserved for symmetry; today reasoning
     * is enabled per-provider via existing options (e.g. Anthropic's
     * `thinking.enabled`). This method does not override those settings.
     *
     * Not calling `withReasoning()` preserves prior behavior: providers honor
     * whatever the user sets via `withProviderOptions()`.
     */
    public function withReasoning(bool $enabled = true): self
    {
        $this->reasoningEnabled = $enabled;

        return $this;
    }

    public function reasoningEnabled(): ?bool
    {
        return $this->reasoningEnabled;
    }
}
