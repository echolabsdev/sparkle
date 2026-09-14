<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class ToolApprovalResponse implements Arrayable
{
    public function __construct(
        public string $approvalId,
        public bool $approved,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'approval_id' => $this->approvalId,
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
