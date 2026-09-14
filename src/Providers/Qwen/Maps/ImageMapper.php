<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    /**
     * DashScope native multimodal format: {"image": "url_or_base64_data_uri"}
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'image' => $this->media->isUrl()
                ? $this->media->url()
                : sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64()),
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Qwen;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
