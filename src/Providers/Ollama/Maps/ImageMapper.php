<?php

namespace Prism\Prism\Providers\Ollama\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    public function toPayload(): mixed
    {
        return $this->media->base64();
    }

    protected function provider(): string|Provider
    {
        return Provider::Ollama;
    }

    /**
     * Ollama takes image BYTES only — it has no way to accept a URL.
     *
     * This used to answer true for a URL, because reading the image's bytes
     * fetched it. With that fetch gone, answering true would pass validation
     * and then send `null` as the image, which is the silent failure the change
     * must not introduce — and nothing tested an Ollama URL image, so nothing
     * would have said so.
     */
    protected function validateMedia(): bool
    {
        return $this->media->hasRawContent();
    }
}
