<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
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
            $output = $toolResult->result;
            if (! is_string($output)) {
                $output = is_array($output) ? json_encode(
                    $output,
                    JSON_THROW_ON_ERROR,
                ) : strval($output);
            }

            $this->mappedMessages[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->toolCallResultId,
                'output' => $output,
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
            ],
            ...$message->additionalAttributes,
        ];
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

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        if ($message->toolCalls !== []) {
            $grouped = collect($message->toolCalls)
                ->groupBy(fn (ToolCall $toolCall): string => $toolCall->reasoningId ?? '');

            foreach ($grouped as $reasoningId => $toolCalls) {
                $first = $toolCalls->first();

                if ($reasoningId !== '' && $first instanceof ToolCall) {
                    $this->mappedMessages[] = [
                        'type' => 'reasoning',
                        'id' => $first->reasoningId,
                        'summary' => $first->reasoningSummary,
                    ];
                }

                foreach ($toolCalls as $toolCall) {
                    $this->mappedMessages[] = [
                        'id' => $toolCall->id,
                        'call_id' => $toolCall->resultId,
                        'type' => 'function_call',
                        'name' => $toolCall->name,
                        'arguments' => $toolCall->argumentsAsJson(),
                    ];
                }
            }
        }

        if ($message->content !== '') {
            $mappedMessage = [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $message->content,
                    ],
                ],
            ];

            if (isset($message->additionalContent['citations'])) {
                $mappedMessage['content'][0]['annotations'] = CitationsMapper::mapToOpenAI($message->additionalContent['citations'][0])['annotations'];
            }

            $this->mappedMessages[] = $mappedMessage;
        }
    }
}
