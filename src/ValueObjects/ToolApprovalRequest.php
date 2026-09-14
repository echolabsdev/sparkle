<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a tool approval request.
 * Tracked on AssistantMessage for correlation with tool calls.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class ToolApprovalRequest implements Arrayable
{
    public function __construct(
        public string $approvalId,
        public string $toolCallId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'approval_id' => $this->approvalId,
            'tool_call_id' => $this->toolCallId,
        ];
    }
}
