<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\ToolCall;

readonly class ToolCallEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public ToolCall $toolCall,      // Tool call value object
        public string $messageId,       // Message this tool call belongs to
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ToolCall;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->argumentsAsObject(),
            'message_id' => $this->messageId,
            'reasoning_id' => $this->toolCall->reasoningId,
        ];
    }
}
