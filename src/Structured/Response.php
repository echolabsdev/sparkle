<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Response implements Arrayable
{
    /**
     * @param  Collection<int, Step>  $steps
     * @param  array<mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ProviderToolCall>  $providerToolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @param  array<string,mixed>  $additionalContent
     * @param  array<string,mixed>|null  $raw
     */
    public function __construct(
        public Collection $steps,
        public string $text,
        public ?array $structured,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public array $toolCalls = [],
        public array $providerToolCalls = [],
        public array $toolResults = [],
        public array $additionalContent = [],
        public ?array $raw = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'steps' => $this->steps->map(fn (Step $step): array => $step->toArray())->toArray(),
            'text' => $this->text,
            'structured' => $this->structured,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'tool_calls' => array_map(fn (ToolCall $toolCall): array => $toolCall->toArray(), $this->toolCalls),
            'provider_tool_calls' => array_map(fn (ProviderToolCall $providerToolCall): array => $providerToolCall->toArray(), $this->providerToolCalls),
            'tool_results' => array_map(fn (ToolResult $toolResult): array => $toolResult->toArray(), $this->toolResults),
            'additional_content' => JsonMap::of($this->additionalContent),
            'raw' => $this->raw,
        ];
    }
}
