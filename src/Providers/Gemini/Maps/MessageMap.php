<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Exception;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap
{
    /** @var array<string, mixed> */
    protected array $contents = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $this->contents['contents'] = [];

        foreach ($this->messages as $message) {
            $this->mapMessage($message);
        }

        foreach ($this->systemPrompts as $systemPrompt) {
            $this->mapSystemMessage($systemPrompt);
        }

        return array_filter($this->contents);
    }

    protected function mapMessage(Message $message): void
    {
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        if (isset($this->contents['system_instruction'])) {
            throw new PrismException('Gemini only supports one system instruction.');
        }

        $this->contents['system_instruction'] = [
            'parts' => [
                [
                    'text' => $message->content,
                ],
            ],
        ];
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        $parts = [];
        foreach ($message->toolResults as $toolResult) {
            $parts[] = [
                'functionResponse' => [
                    'name' => $toolResult->toolName,
                    'response' => [
                        'name' => $toolResult->toolName,
                        'content' => json_encode($toolResult->result),
                    ],
                ],
            ];
        }

        $this->contents['contents'][] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $parts = [];

        if ($message->text() !== '') {
            $parts[] = ['text' => $message->text()];
        }

        // Gemini docs suggest including text prompt after documents, but before images.
        $parts = array_merge(
            $this->mapDocuments($message->documents()),
            $parts,
            $this->mapImages($message->images()),
            $this->mapVideo($message->videos()),
            $this->mapAudio($message->audios()),
        );

        $this->contents['contents'][] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $parts = [];

        if ($message->content !== '') {
            $parts[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $parts[] = Arr::whereNotNull([
                'functionCall' => [
                    'name' => $toolCall->name,
                    ...$toolCall->hasArguments() ? [
                        'args' => $toolCall->argumentsAsObject(),
                    ] : [],
                ],
                'thoughtSignature' => $toolCall->reasoningId,
            ]);
        }

        $this->contents['contents'][] = [
            'role' => 'model',
            'parts' => $parts,
        ];
    }

    /**
     * @param  Image[]  $images
     * @return array<string,array<string,mixed>>
     */
    protected function mapImages(array $images): array
    {
        return array_map(fn (Image $image): array => (new ImageMapper($image))->toPayload(), $images);
    }

    /**
     * @param  Media[]|Video[]  $video
     * @return array<string,array<string,mixed>>
     */
    protected function mapVideo(array $video): array
    {
        return array_map(fn (Video|Media $media): array => (new AudioVideoMapper($media))->toPayload(), $video);
    }

    /**
     * @param  Media[]|Audio[]  $audio
     * @return array<string,array<string,mixed>>
     */
    protected function mapAudio(array $audio): array
    {
        return array_map(fn (Audio|Media $media): array => (new AudioVideoMapper($media))->toPayload(), $audio);
    }

    /**
     * @param  Document[]  $documents
     * @return array<string,array<string,mixed>>
     */
    protected function mapDocuments(array $documents): array
    {
        return array_map(fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $documents);
    }
}
