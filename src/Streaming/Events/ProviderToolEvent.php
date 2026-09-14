<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Support\JsonMap;

readonly class ProviderToolEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $toolType,
        public string $status,
        public string $itemId,
        public array $data,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::ProviderToolEvent;
    }

    public function eventKey(): string
    {
        return "provider_tool_event.{$this->toolType}.{$this->status}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'type' => $this->type()->value,
            'event_key' => $this->eventKey(),
            'tool_type' => $this->toolType,
            'status' => $this->status,
            'item_id' => $this->itemId,
            'data' => JsonMap::of($this->data),
        ];
    }
}
