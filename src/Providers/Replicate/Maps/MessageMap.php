<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Maps;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap
{
    /**
     * Map Prism messages to Replicate prompt format.
     *
     * @param  array<int, Message>  $messages
     */
    public static function map(array $messages): string
    {
        $prompt = '';

        foreach ($messages as $message) {
            $prompt .= match ($message::class) {
                SystemMessage::class => self::mapSystemMessage($message),
                UserMessage::class => self::mapUserMessage($message),
                AssistantMessage::class => self::mapAssistantMessage($message),
                ToolResultMessage::class => self::mapToolResultMessage($message),
                default => '',
            };
        }

        return trim($prompt);
    }

    protected static function mapSystemMessage(SystemMessage $message): string
    {
        return "System: {$message->content}\n\n";
    }

    protected static function mapUserMessage(UserMessage $message): string
    {
        return "User: {$message->text()}\n\n";
    }

    protected static function mapAssistantMessage(AssistantMessage $message): string
    {
        return "Assistant: {$message->content}\n\n";
    }

    protected static function mapToolResultMessage(ToolResultMessage $message): string
    {
        $results = [];

        foreach ($message->toolResults as $result) {
            $resultText = is_string($result->result)
                ? $result->result
                : json_encode($result->result);

            $results[] = sprintf(
                'Tool: %s\nResult: %s',
                $result->toolName,
                $resultText
            );
        }

        return "Tool Results:\n".implode("\n\n", $results)."\n\n";
    }
}
