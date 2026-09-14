<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Support\JsonMap;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class ToolResult implements Arrayable
{
    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $result
     * @param  array<int, Artifact>  $artifacts
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public int|float|string|array|null $result,
        public ?string $toolCallResultId = null,
        public array $artifacts = [],
    ) {}

    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'args' => JsonMap::of($this->args),
            'result' => $this->result,
            'tool_call_result_id' => $this->toolCallResultId,
            'artifacts' => array_map(fn (Artifact $artifact): array => $artifact->toArray(), $this->artifacts),
        ];
    }
}
