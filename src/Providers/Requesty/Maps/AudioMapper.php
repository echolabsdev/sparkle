<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

/**
 * NOTE: mirrored from Gemini's AudioVideoMapper so future refactors can consolidate.
 */
class AudioMapper extends ProviderMediaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => 'input_audio',
            'input_audio' => [
                'data' => $this->media->base64(),
                'format' => $this->determineFormat(),
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Requesty;
    }

    protected function validateMedia(): bool
    {
        return $this->media->hasRawContent();
    }

    protected function determineFormat(): string
    {
        $mimeType = $this->media->mimeType();

        return match ($mimeType) {
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            default => 'wav',
        };
    }
}
