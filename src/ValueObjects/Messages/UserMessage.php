<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Support\JsonMap;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;

/**
 * @implements Arrayable<string, mixed>
 */
class UserMessage implements Arrayable, Message
{
    use HasProviderOptions;

    /**
     * @param  array<int, Text|Image|Document|Media>  $additionalContent
     * @param  array<string, mixed>  $additionalAttributes
     */
    public function __construct(
        public readonly string $content,
        public array $additionalContent = [],
        public readonly array $additionalAttributes = [],
    ) {
        $this->additionalContent[] = new Text($content);
    }

    public function text(): string
    {
        $result = '';

        foreach ($this->additionalContent as $content) {
            if ($content instanceof Text) {
                $result .= $content->text;
            }
        }

        return $result;
    }

    /**
     * @return Image[]
     */
    public function images(): array
    {
        /** @phpstan-ignore return.type */
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Image)
            ->values()
            ->all();
    }

    /**
     * @return array<int, Audio|Video|Media>
     */
    public function media(): array
    {
        /** @phpstan-ignore return.type */
        return collect($this->additionalContent)
            ->filter(fn ($part): bool => $part instanceof Audio || $part instanceof Video || $part instanceof Media)
            ->values()
            ->all();
    }

    /**
     * Note: Prism currently only supports Documents with Anthropic and OpenRouter.
     *
     * @return Document[]
     */
    public function documents(): array
    {
        /** @phpstan-ignore return.type */
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Document)
            ->values()
            ->all();
    }

    /**
     * @return Audio[]
     */
    public function audios(): array
    {
        /** @phpstan-ignore return.type */
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Audio)
            ->values()
            ->all();
    }

    /**
     * @return Video[]
     */
    public function videos(): array
    {
        /** @phpstan-ignore return.type */
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Video)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'user',
            'content' => $this->content,
            'additional_content' => array_map(
                fn (Text|Image|Document|Media $content): array => $content->toArray(),
                $this->additionalContent
            ),
            'additional_attributes' => JsonMap::of($this->additionalAttributes),
        ];
    }
}
