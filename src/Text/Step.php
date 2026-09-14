<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Step implements Arrayable
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ProviderToolCall[]  $providerToolCalls
     * @param  Message[]  $messages
     * @param  SystemMessage[]  $systemPrompts
     * @param  array<string,mixed>  $additionalContent
     * @param  array<string,mixed>|null  $raw
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public array $toolCalls,
        public array $toolResults,
        public array $providerToolCalls,
        public Usage $usage,
        public Meta $meta,
        public array $messages,
        public array $systemPrompts,
        public array $additionalContent = [],
        public ?array $raw = null,
        public array $toolApprovalRequests = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'finish_reason' => $this->finishReason->value,
            'tool_calls' => array_map(fn (ToolCall $toolCall): array => $toolCall->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolResult $toolResult): array => $toolResult->toArray(), $this->toolResults),
            'provider_tool_calls' => array_map(fn (ProviderToolCall $providerToolCall): array => $providerToolCall->toArray(), $this->providerToolCalls),
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'messages' => array_map($this->messageToArray(...), $this->messages),
            'system_prompts' => array_map(fn (SystemMessage $systemMessage): array => $systemMessage->toArray(), $this->systemPrompts),
            'additional_content' => JsonMap::of($this->additionalContent),
            'raw' => $this->raw,
            'tool_approval_requests' => array_map(fn (ToolApprovalRequest $request): array => $request->toArray(), $this->toolApprovalRequests),
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
