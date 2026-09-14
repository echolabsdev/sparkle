<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\ValueObjects\Media\Document;
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
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts
    ) {
        $this->messages = array_merge(
            $this->systemPrompts,
            $this->messages
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function __invoke(): array
    {
        array_map(
            $this->mapMessage(...),
            $this->messages
        );

        return $this->mappedMessages;
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
                'content' => $toolResult->result,
                'tool_call_id' => $toolResult->toolCallId,
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
            ],
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $toolCalls = array_map(fn (ToolCall $toolCall): array => [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => $toolCall->argumentsAsJson(),
            ],
        ], $message->toolCalls);

        $this->mappedMessages[] = Payload::compact([
            'role' => 'assistant',
            'content' => $message->content,
            'tool_calls' => $toolCalls,
        ]);
    }

    /**
     * @param  Image[]  $images
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $images): array
    {
        return array_map(fn (Image $image): array => (new ImageMapper($image))->toPayload(), $images);
    }

    /**
     * @param  Document[]  $documents
     * @return array<int,mixed>
     */
    protected static function mapDocumentParts(array $documents): array
    {
        return array_map(fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $documents);
    }
}
