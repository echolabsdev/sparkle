<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class GeneratedAudio extends Media\Media
{
    public const KIND = 'audio';

    public function __construct(?string $base64 = null, public ?string $type = null)
    {
        parent::__construct(null, $base64, $type);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'type' => $this->type,
        ]);
    }
}
