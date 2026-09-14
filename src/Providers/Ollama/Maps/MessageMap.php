<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

class MessageMap
{
    /** @var array<int, mixed> */
    protected array $mappedMessages = [];

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(
        protected array $messages,
    ) {}

    /**
     * @return array<int, array{role: string, content: string, images?: array<string>}>
     */
    public function map(): array
    {
        array_map(
            $this->mapMessage(...),
            $this->messages
        );

        return array_values($this->mappedMessages);
    }

    protected function mapMessage(Message $message): void
    {
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            SystemMessage::class => $this->mapSystemMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'system',
            'content' => $message->content,
        ];
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        foreach ($message->toolResults as $toolResult) {
            $this->mappedMessages[] = [
                'role' => 'tool',
                'tool_name' => $toolResult->toolName,
                'content' => is_string($toolResult->result)
                    ? $toolResult->result
                    : (json_encode($toolResult->result) ?: ''),
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $mapped = [
            'role' => 'user',
            'content' => $message->text(),
        ];

        if ($images = $message->images()) {
            $mapped['images'] = array_map(
                fn (Image $image): string => (new ImageMapper($image))->toPayload(),
                $images
            );
        }

        $this->mappedMessages[] = $mapped;
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $this->mappedMessages[] = Payload::compact([
            'role' => 'assistant',
            'content' => $message->content,
            'tool_calls' => $message->toolCalls ? array_map(fn (ToolCall $toolCall): array => [
                'function' => [
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->argumentsAsObject(),
                ],
            ], $message->toolCalls) : null,
        ]);
    }
}
