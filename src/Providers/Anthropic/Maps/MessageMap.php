<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\NormalizesCacheControl;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\Support\Json;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    use NormalizesCacheControl;

    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    public static function map(array $messages, array $requestProviderOptions = []): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Anthropic does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        $mappedMessages = array_map(
            fn (Message $message): array => self::mapMessage($message, $requestProviderOptions),
            $messages
        );

        if (isset($requestProviderOptions['tool_result_cache_type'])) {
            $lastToolResultIndex = null;

            for ($i = count($mappedMessages) - 1; $i >= 0; $i--) {
                if ($mappedMessages[$i]['role'] === 'user' &&
                    isset($mappedMessages[$i]['content'][0]['type']) &&
                    $mappedMessages[$i]['content'][0]['type'] === 'tool_result') {
                    $lastToolResultIndex = $i;
                    break;
                }
            }

            if ($lastToolResultIndex !== null) {
                $lastContent = &$mappedMessages[$lastToolResultIndex]['content'];
                $lastContent[count($lastContent) - 1]['cache_control'] = [
                    'type' => $requestProviderOptions['tool_result_cache_type'],
                ];
            }
        }

        return $mappedMessages;
    }

    /**
     * @param  SystemMessage[]  $messages
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $messages): array
    {
        return array_map(
            self::mapSystemMessage(...),
            $messages
        );
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message, array $requestProviderOptions = []): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message, $requestProviderOptions),
            AssistantMessage::class => self::mapAssistantMessage($message),
            ToolResultMessage::class => self::mapToolResultMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapSystemMessage(SystemMessage $systemMessage): array
    {
        return Payload::compact([
            'type' => 'text',
            'text' => $systemMessage->content,
            'cache_control' => self::normalizeCacheControl($systemMessage),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        $toolResults = $message->toolResults;
        $totalResults = count($toolResults);

        return [
            'role' => 'user',
            'content' => array_map(function (ToolResult $toolResult, int $index) use ($message, $totalResults): array {
                // Only add cache_control to the last tool result
                $isLastResult = $index === $totalResults - 1;

                return Payload::compact([
                    'type' => 'tool_result',
                    'tool_use_id' => $toolResult->toolCallId,
                    'content' => $toolResult->result,
                    'cache_control' => $isLastResult ? self::normalizeCacheControl($message) : null,
                ]);
            }, $toolResults, array_keys($toolResults)),
        ];
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message, array $requestProviderOptions = []): array
    {
        $cacheControl = self::normalizeCacheControl($message);

        return [
            'role' => 'user',
            'content' => [
                Payload::compact([
                    'type' => 'text',
                    'text' => $message->text(),
                    'cache_control' => $cacheControl,
                ]),
                ...self::mapImageParts($message->images(), $cacheControl),
                ...self::mapDocumentParts($message->documents(), $cacheControl, $requestProviderOptions),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $cacheControl = self::normalizeCacheControl($message);

        $content = [];

        if (isset($message->additionalContent['thinking']) && isset($message->additionalContent['thinking_signature'])) {
            $content[] = [
                'type' => 'thinking',
                'thinking' => $message->additionalContent['thinking'],
                'signature' => $message->additionalContent['thinking_signature'],
            ];
        }

        if (isset($message->additionalContent['citations'])) {
            foreach ($message->additionalContent['citations'] as $part) {
                $content[] = Payload::compact([
                    ...CitationsMapper::mapToAnthropic($part),
                    'cache_control' => $cacheControl,
                ]);
            }
        } elseif ($message->content !== '') {

            // Payload::compact, not array_filter: a bare array_filter would
            // also drop 'text' => '0', which is falsy but is real output.
            $content[] = Payload::compact([
                'type' => 'text',
                'text' => $message->content,
                'cache_control' => $cacheControl,
            ]);
        }

        $toolCalls = $message->toolCalls
            ? array_map(fn (ToolCall $toolCall): array => [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->argumentsAsObject(),
            ], $message->toolCalls)
            : [];

        if (isset($message->additionalContent['provider_tool_calls'])) {
            foreach ($message->additionalContent['provider_tool_calls'] as $toolCall) {
                $content[] = Payload::compact([
                    'type' => $toolCall['type'] ?? 'server_tool_use',
                    'id' => $toolCall['id'] ?? null,
                    'name' => $toolCall['name'] ?? null,
                    'input' => isset($toolCall['input']) && $toolCall['input'] !== '' ? Json::decode((string) $toolCall['input'], preservingContainerTypes: true) : new \stdClass,
                ]);
            }
        }

        if (isset($message->additionalContent['provider_tool_results'])) {
            foreach ($message->additionalContent['provider_tool_results'] as $toolResult) {
                $content[] = Payload::compact([
                    'type' => $toolResult['type'],
                    'tool_use_id' => $toolResult['tool_use_id'] ?? null,
                    'content' => $toolResult['content'] ?? null,
                ]);
            }
        }

        return [
            'role' => 'assistant',
            'content' => array_merge($content, $toolCalls),
        ];
    }

    /**
     * @param  Image[]  $parts
     * @param  array<string, mixed>|null  $cacheControl
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts, ?array $cacheControl = null): array
    {
        return array_map(
            fn (Image $image): array => (new ImageMapper($image, $cacheControl))->toPayload(),
            $parts
        );
    }

    /**
     * @param  Document[]  $parts
     * @param  array<string, mixed>|null  $cacheControl
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    protected static function mapDocumentParts(array $parts, ?array $cacheControl = null, array $requestProviderOptions = []): array
    {
        return array_map(
            fn (Document $document): array => (new DocumentMapper($document, $cacheControl, $requestProviderOptions))->toPayload(),
            $parts
        );
    }
}
