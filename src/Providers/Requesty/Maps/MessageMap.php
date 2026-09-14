<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use BackedEnum;
use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

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
        $cacheType = $message->providerOptions('cacheType');

        // Requesty supports cache_control in content array format (same as Anthropic)
        if ($cacheType) {
            $this->mappedMessages[] = [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $message->content,
                        'cache_control' => ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType],
                    ],
                ],
            ];
        } else {
            $this->mappedMessages[] = [
                'role' => 'system',
                'content' => $message->content,
            ];
        }
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        $cacheType = $message->providerOptions('cacheType');
        $cacheControl = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        $toolResults = $message->toolResults;
        $totalResults = count($toolResults);

        // Requesty supports cache_control in content array format
        if ($cacheControl) {
            $content = array_map(function (ToolResult $toolResult, int $index) use ($cacheControl, $totalResults): array {
                // Only add cache_control to the last tool result
                $isLastResult = $index === $totalResults - 1;

                return Payload::compact([
                    'type' => 'tool_result',
                    'tool_call_id' => $toolResult->toolCallId,
                    'content' => $toolResult->result,
                    'cache_control' => $isLastResult ? $cacheControl : null,
                ]);
            }, $toolResults, array_keys($toolResults));

            $this->mappedMessages[] = [
                'role' => 'tool',
                'content' => $content,
            ];
        } else {
            // Legacy format without caching
            foreach ($toolResults as $toolResult) {
                $this->mappedMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolResult->toolCallId,
                    'content' => $toolResult->result,
                ];
            }
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $cacheType = $message->providerOptions('cacheType');
        $cacheControl = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        $imageParts = array_map(fn (Image $image): array => (new ImageMapper($image))->toPayload(), $message->images());
        // NOTE: mirrored from Gemini's multimodal mapper so we stay consistent across providers.
        $audioParts = array_map(fn (Audio $audio): array => (new AudioMapper($audio))->toPayload(), $message->audios());
        $videoParts = array_map(fn (Video $video): array => (new VideoMapper($video))->toPayload(), $message->videos());
        $documentParts = array_map(fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $message->documents());

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                Payload::compact([
                    'type' => 'text',
                    'text' => $message->text(),
                    'cache_control' => $cacheControl,
                ]),
                ...$imageParts,
                ...$audioParts,
                ...$videoParts,
                ...$documentParts,
            ],
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $cacheType = $message->providerOptions('cacheType');

        $toolCalls = array_map(fn (ToolCall $toolCall): array => [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => $toolCall->argumentsAsJson(),
            ],
        ], $message->toolCalls);

        // Requesty supports cache_control on assistant messages
        if ($cacheType && $message->content !== '') {
            $this->mappedMessages[] = Payload::compact([
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $message->content,
                        'cache_control' => ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType],
                    ],
                ],
                'tool_calls' => $toolCalls,
            ]);
        } else {
            $this->mappedMessages[] = Payload::compact([
                'role' => 'assistant',
                'content' => $message->content,
                'tool_calls' => $toolCalls,
            ]);
        }
    }
}
