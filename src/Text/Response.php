<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
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
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Collection<int, Message>  $messages
     * @param  array<string,mixed>  $additionalContent
     * @param  array<string,mixed>|null  $raw
     */
    public function __construct(
        public Collection $steps,
        public string $text,
        public FinishReason $finishReason,
        public array $toolCalls,
        public array $toolResults,
        public Usage $usage,
        public Meta $meta,
        public Collection $messages,
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
            'finish_reason' => $this->finishReason->value,
            'tool_calls' => array_map(fn (ToolCall $toolCall): array => $toolCall->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolResult $toolResult): array => $toolResult->toArray(), $this->toolResults),
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'messages' => $this->messages->map($this->messageToArray(...))->toArray(),
            'additional_content' => JsonMap::of($this->additionalContent),
            'raw' => $this->raw,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageToArray(Message $message): array
    {
        if ($message instanceof UserMessage || $message instanceof AssistantMessage || $message instanceof ToolResultMessage || $message instanceof SystemMessage) {
            return $message->toArray();
        }

        return ['type' => 'unknown'];
    }
}
