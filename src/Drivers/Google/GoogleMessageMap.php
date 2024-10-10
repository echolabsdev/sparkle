<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Drivers\Google;

use EchoLabs\Prism\Contracts\Message;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\SystemMessage;
use EchoLabs\Prism\ValueObjects\Messages\ToolResultMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
use EchoLabs\Prism\ValueObjects\ToolResult;
use Exception;

class GoogleMessageMap
{
    private ?string $systemInstruction = null;

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(public readonly array $messages)
    {
        $this->extractSystemInstruction();
    }

    /**
     * @return array{system_instruction?: array<string, mixed>, contents: array<int, mixed>}
     */
    public function __invoke(): array
    {
        $result = [
            'contents' => array_values(array_filter(
                array_map(
                    fn (Message $message): ?array => $this->mapMessage($message),
                    $this->messages
                )
            )),
        ];

        if ($this->systemInstruction !== null) {
            $result['system_instruction'] = [
                'parts' => [
                    ['text' => $this->systemInstruction],
                ],
            ];
        }

        return $result;
    }

    protected function extractSystemInstruction(): void
    {
        foreach ($this->messages as $index => $message) {
            if ($message instanceof SystemMessage) {
                $this->systemInstruction = $message->content();
                unset($this->messages[$index]);
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mapMessage(Message $message): ?array
    {
        return match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            SystemMessage::class => null, // System messages are handled separately
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapUserMessage(UserMessage $message): array
    {
        return [
            'role' => 'user',
            'parts' => [
                ['text' => $message->content()],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapAssistantMessage(AssistantMessage $message): array
    {
        $parts = [];

        if ($message->content() !== '' && $message->content() !== '0') {
            $parts[] = ['text' => $message->content()];
        }

        foreach ($message->toolCalls() as $toolCall) {
            $parts[] = [
                'functionCall' => [
                    'name' => $toolCall->name,
                    'args' => $toolCall->arguments(),
                ],
            ];
        }

        return [
            'role' => 'model',
            'parts' => $parts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'function',
            'parts' => array_map(fn (ToolResult $toolResult): array => [
                'functionResponse' => [
                    'name' => $toolResult->toolCallId,
                    'response' => [
                        'name' => $toolResult->toolCallId,
                        'content' => $toolResult->result,
                    ],
                ],
            ], $message->toolResults),
        ];
    }
}
