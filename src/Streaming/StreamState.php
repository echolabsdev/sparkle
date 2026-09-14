<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

class StreamState
{
    protected string $messageId = '';

    protected string $reasoningId = '';

    protected bool $streamStarted = false;

    protected bool $stepStarted = false;

    protected bool $textStarted = false;

    protected bool $thinkingStarted = false;

    protected string $currentText = '';

    protected string $currentThinking = '';

    /**
     * @var array<array-key, string>
     */
    protected array $thinkingSummaries = [];

    protected ?int $currentBlockIndex = null;

    protected ?string $currentBlockType = null;

    /** @var array<int, array<string, mixed>> */
    protected array $toolCalls = [];

    /** @var array<MessagePartWithCitations> */
    protected array $citations = [];

    protected ?Usage $usage = null;

    protected ?FinishReason $finishReason = null;

    protected string $model = '';

    protected string $provider = '';

    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;

    public function withMessageId(string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function withReasoningId(string $reasoningId): self
    {
        $this->reasoningId = $reasoningId;

        return $this;
    }

    public function withModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function withProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function withMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function markStreamStarted(): self
    {
        $this->streamStarted = true;

        return $this;
    }

    public function markStepStarted(): self
    {
        $this->stepStarted = true;

        return $this;
    }

    public function markStepFinished(): self
    {
        $this->stepStarted = false;

        return $this;
    }

    public function markTextStarted(): self
    {
        $this->textStarted = true;

        return $this;
    }

    public function markTextCompleted(): self
    {
        $this->textStarted = false;

        return $this;
    }

    public function markThinkingStarted(): self
    {
        $this->thinkingStarted = true;

        return $this;
    }

    public function markThinkingCompleted(): self
    {
        $this->thinkingStarted = false;

        return $this;
    }

    public function appendText(string $text): self
    {
        $this->currentText .= $text;

        return $this;
    }

    public function appendThinking(string $thinking): self
    {
        $this->currentThinking .= $thinking;
        $this->thinkingSummaries[] = $thinking;

        return $this;
    }

    public function withText(string $text): self
    {
        $this->currentText = $text;

        return $this;
    }

    public function withThinking(string $thinking): self
    {
        $this->currentThinking = $thinking;

        return $this;
    }

    public function withBlockContext(int $index, string $type): self
    {
        $this->currentBlockIndex = $index;
        $this->currentBlockType = $type;

        return $this;
    }

    public function resetBlockContext(): self
    {
        $this->currentBlockIndex = null;
        $this->currentBlockType = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $toolCall
     */
    public function addToolCall(int $index, array $toolCall): self
    {
        $this->toolCalls[$index] = $toolCall;

        return $this;
    }

    public function appendToolCallInput(int $index, string $input): self
    {
        if (! isset($this->toolCalls[$index])) {
            $this->toolCalls[$index] = ['input' => ''];
        }

        $this->toolCalls[$index]['input'] .= $input;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateToolCall(int $index, array $data): self
    {
        $this->toolCalls[$index] = array_merge(
            $this->toolCalls[$index] ?? [],
            $data
        );

        return $this;
    }

    public function addCitation(MessagePartWithCitations $citation): self
    {
        $this->citations[] = $citation;

        return $this;
    }

    public function withUsage(Usage $usage): self
    {
        $this->usage = $usage;

        return $this;
    }

    public function addUsage(Usage $usage): self
    {
        if (! $this->usage instanceof Usage) {
            $this->usage = $usage;

            return $this;
        }

        $this->usage = new Usage(
            promptTokens: $this->usage->promptTokens + $usage->promptTokens,
            completionTokens: $this->usage->completionTokens + $usage->completionTokens,
            cacheWriteInputTokens: ($this->usage->cacheWriteInputTokens ?? 0) + ($usage->cacheWriteInputTokens ?? 0),
            cacheReadInputTokens: ($this->usage->cacheReadInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0),
            thoughtTokens: ($this->usage->thoughtTokens ?? 0) + ($usage->thoughtTokens ?? 0),
            cost: ($this->usage->cost !== null || $usage->cost !== null)
                ? ($this->usage->cost ?? 0.0) + ($usage->cost ?? 0.0)
                : null,
        );

        return $this;
    }

    public function withFinishReason(FinishReason $finishReason): self
    {
        $this->finishReason = $finishReason;

        return $this;
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function reasoningId(): string
    {
        return $this->reasoningId;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    public function hasStreamStarted(): bool
    {
        return $this->streamStarted;
    }

    public function hasTextStarted(): bool
    {
        return $this->textStarted;
    }

    public function hasThinkingStarted(): bool
    {
        return $this->thinkingStarted;
    }

    public function currentText(): string
    {
        return $this->currentText;
    }

    public function currentThinking(): string
    {
        return $this->currentThinking;
    }

    /**
     * @return array<array-key, string>
     */
    public function thinkingSummaries(): array
    {
        return $this->thinkingSummaries;
    }

    public function currentBlockIndex(): ?int
    {
        return $this->currentBlockIndex;
    }

    public function currentBlockType(): ?string
    {
        return $this->currentBlockType;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * @return array<MessagePartWithCitations>
     */
    public function citations(): array
    {
        return $this->citations;
    }

    public function usage(): ?Usage
    {
        return $this->usage;
    }

    public function finishReason(): ?FinishReason
    {
        return $this->finishReason;
    }

    public function shouldEmitStreamStart(): bool
    {
        return ! $this->streamStarted;
    }

    public function shouldEmitStepStart(): bool
    {
        return ! $this->stepStarted;
    }

    public function shouldEmitTextStart(): bool
    {
        return ! $this->textStarted;
    }

    public function shouldEmitThinkingStart(): bool
    {
        return ! $this->thinkingStarted;
    }

    public function reset(): self
    {
        $this->messageId = '';
        $this->reasoningId = '';
        // Note: streamStarted is intentionally NOT reset here.
        // Fresh state is created per-call via constructor; reset() is only called
        // between tool-call turns where we need to preserve streamStarted = true.
        $this->textStarted = false;
        $this->thinkingStarted = false;
        $this->currentText = '';
        $this->currentThinking = '';
        $this->currentBlockIndex = null;
        $this->currentBlockType = null;
        $this->toolCalls = [];
        $this->citations = [];
        $this->model = '';
        $this->provider = '';
        $this->metadata = null;

        return $this;
    }

    public function resetTextState(): self
    {
        $this->messageId = '';
        $this->textStarted = false;
        $this->thinkingStarted = false;
        $this->currentText = '';
        $this->currentThinking = '';

        return $this;
    }

    public function resetBlock(): self
    {
        $this->currentBlockIndex = null;
        $this->currentBlockType = null;

        return $this;
    }
}
