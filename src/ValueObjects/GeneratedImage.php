<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class GeneratedImage extends Media\Media
{
    public const KIND = 'image';

    public function __construct(
        ?string $url = null,
        ?string $base64 = null,
        public ?string $revisedPrompt = null,
        ?string $mimeType = null
    ) {
        parent::__construct($url, $base64, $mimeType);
    }

    public function hasRevisedPrompt(): bool
    {
        return $this->revisedPrompt !== null;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'revised_prompt' => $this->revisedPrompt,
        ]);
    }
}
