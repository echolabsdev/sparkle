<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
readonly class StreamContent implements Arrayable
{
    /**
     * @param  array<int, array<string, mixed>>  $systemPrompts
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    public function __construct(
        public string $text,
        public array $systemPrompts = [],
        public array $messages = [],
        public array $toolCalls = [],
        public bool $truncated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'system_prompts' => $this->systemPrompts,
            'messages' => $this->messages,
            'tool_calls' => $this->toolCalls,
            'truncated' => $this->truncated,
        ];
    }
}
