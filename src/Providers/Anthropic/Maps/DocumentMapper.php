<?php

namespace Prism\Prism\Providers\Anthropic\Maps;

use Illuminate\Support\Str;
use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;

class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @param  Document  $media
     * @param  array<string, mixed>  $cacheControl
     * @param  array<string, mixed>  $requestProviderOptions
     */
    public function __construct(
        public readonly Media $media,
        public ?array $cacheControl = null,
        public array $requestProviderOptions = [],
    ) {
        $this->runValidation();
    }

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $providerOptions = $this->media->providerOptions();

        $payload = [
            'type' => 'document',
            'title' => $this->media->documentTitle(),
            'context' => $providerOptions['context'] ?? null,
            'cache_control' => $this->cacheControl,
            'citations' => data_get($this->requestProviderOptions, 'citations', data_get($providerOptions, 'citations', false))
                ? ['enabled' => true]
                : null,
        ];

        if ($this->media->isFileId()) {
            $payload['source'] = [
                'type' => 'file',
                'file_id' => $this->media->fileId(),
            ];
        } elseif ($this->media->isUrl()) {
            $payload['source'] = [
                'type' => 'url',
                'url' => $this->media->url(),
            ];
        } elseif ($this->media->isChunks()) {
            $payload['source'] = [
                'type' => 'content',
                'content' => array_map(fn (string $chunk): array => ['type' => 'text', 'text' => $chunk], $this->media->chunks() ?? []),
            ];
        } elseif ($this->media->mimeType() && Str::startsWith($this->media->mimeType(), 'text/')) {
            $payload['source'] = [
                'type' => 'text',
                // Anthropic's text source takes text/plain and nothing else, so
                // text/markdown, text/csv or a charset parameter failed the whole
                // request. The content is sent as given.
                'media_type' => 'text/plain',
                'data' => $this->media->rawContent(),
            ];
        } else {
            $payload['source'] = [
                'type' => 'base64',
                'media_type' => $this->media->mimeType(),
                'data' => $this->media->base64(),
            ];
        }

        return array_filter($payload);
    }

    protected function provider(): string|Provider
    {
        return Provider::Anthropic;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isFileId()) {
            return true;
        }

        if ($this->media->isUrl()) {
            return true;
        }

        if ($this->media->isChunks()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
