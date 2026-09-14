<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

readonly class ResponseBuilder
{
    /** @var Collection<int, Step> */
    public Collection $steps;

    public function __construct()
    {
        $this->steps = new Collection;
    }

    public function addStep(Step $step): self
    {
        $this->steps->push($step);

        return $this;
    }

    public function toResponse(): Response
    {
        /** @var Step $finalStep */
        $finalStep = $this->steps->last();

        return new Response(
            steps: $this->steps,
            text: $finalStep->text,
            structured: $this->extractFinalStructuredData($finalStep),
            finishReason: $finalStep->finishReason,
            usage: $this->calculateTotalUsage(),
            meta: $finalStep->meta,
            toolCalls: $this->aggregateToolCalls(),
            providerToolCalls: $this->aggregateProviderToolCalls(),
            toolResults: $this->aggregateToolResults(),
            additionalContent: $finalStep->additionalContent,
            raw: $finalStep->raw,
        );
    }

    /**
     * @return array<mixed>
     */
    protected function extractFinalStructuredData(Step $finalStep): array
    {
        if ($this->shouldDecodeFromText($finalStep)) {
            return $this->decodeObject($finalStep->text);
        }

        return $finalStep->structured;
    }

    protected function shouldDecodeFromText(Step $finalStep): bool
    {
        return $finalStep->structured === []
            && $finalStep->finishReason === FinishReason::Stop;
    }

    /**
     * @return array<mixed>
     */
    protected function decodeObject(string $responseText): array
    {
        try {
            $pattern = '/^```(?:json)?\s*\n?(.*?)\n?```$/s';

            if (preg_match($pattern, trim($responseText), $matches)) {
                $responseText = trim($matches[1]);
            }

            return json_decode($responseText, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PrismStructuredDecodingException::make($responseText);
        }
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function aggregateToolCalls(): array
    {
        return $this->steps
            ->flatMap(fn (Step $step): array => $step->toolCalls)
            ->values()
            ->all();
    }

    /**
     * @return array<int, ProviderToolCall>
     */
    protected function aggregateProviderToolCalls(): array
    {
        return $this->steps
            ->flatMap(fn (Step $step): array => $step->providerToolCalls)
            ->values()
            ->all();
    }

    /**
     * @return array<int, ToolResult>
     */
    protected function aggregateToolResults(): array
    {
        return $this->steps
            ->flatMap(fn (Step $step): array => $step->toolResults)
            ->values()
            ->all();
    }

    protected function calculateTotalUsage(): Usage
    {
        return new Usage(
            promptTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->promptTokens),
            completionTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->completionTokens),
            cacheWriteInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheWriteInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheWriteInputTokens ?? 0)
                : null,
            cacheReadInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheReadInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheReadInputTokens ?? 0)
                : null,
            thoughtTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->thoughtTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->thoughtTokens ?? 0)
                : null,
            cost: $this->steps->contains(fn (Step $result): bool => $result->usage->cost !== null)
                ? (float) $this->steps->sum(fn (Step $result): float => $result->usage->cost ?? 0.0)
                : null,
        );
    }
}
