<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\ToolCall;

readonly class ToolApprovalRequestEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public ToolCall $toolCall,
        public string $messageId,
        public string $approvalId,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ToolApprovalRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'approval_id' => $this->approvalId,
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->argumentsAsObject(),
            'message_id' => $this->messageId,
        ];
    }
}
