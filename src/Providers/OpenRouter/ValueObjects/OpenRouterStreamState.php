<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class OpenRouterStreamState extends StreamState
{
    /** @var array<int, array<string, mixed>> */
    protected array $reasoningDetails = [];

    /**
     * @param  array<string, mixed>  $detail
     */
    public function addReasoningDetail(array $detail): self
    {
        $this->reasoningDetails[] = $detail;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reasoningDetails(): array
    {
        return $this->reasoningDetails;
    }

    public function reset(): self
    {
        parent::reset();
        $this->reasoningDetails = [];

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->reasoningDetails = [];

        return $this;
    }
}
