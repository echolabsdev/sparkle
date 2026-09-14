<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Usage implements Arrayable
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public ?int $cacheWriteInputTokens = null,
        public ?int $cacheReadInputTokens = null,
        /**
         * Reasoning tokens, and a BREAKDOWN OF `completionTokens` rather than an
         * addition to it.
         *
         * Anthropic reports 1240 thinking tokens inside 2820 output tokens, not
         * beside them, and OpenAI's `reasoning_tokens` works the same way. So
         * pricing `completionTokens + thoughtTokens` double-counts the thinking,
         * which is the expensive half.
         *
         * Stated here because the field is null on providers that do not report
         * it, and a null reads as "no thinking" rather than "not measured".
         */
        public ?int $thoughtTokens = null,
        public ?float $cost = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'thought_tokens' => $this->thoughtTokens,
            'cost' => $this->cost,
        ];
    }
}
