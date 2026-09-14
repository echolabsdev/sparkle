<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

/**
 * @property Document $media
 */
class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => 'file',
            'file' => [
                'filename' => $this->media->documentTitle() ?? 'document',
                'file_data' => $this->media->isUrl()
                    ? $this->media->url()
                    : sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64()),
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Requesty;
    }

    protected function validateMedia(): bool
    {
        // Chunks are Anthropic-specific, not supported via Requesty
        if ($this->media->isChunks()) {
            return false;
        }

        // File IDs are not supported by Requesty
        if ($this->media->isFileId()) {
            return false;
        }

        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
