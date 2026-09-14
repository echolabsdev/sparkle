<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Gemini\Support\MediaUrlDetector;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $url = $this->media->url();

        if ($this->media->isUrl() && $url !== null && MediaUrlDetector::shouldPassAsFileUri($url)) {
            $payload = [
                'file_data' => [
                    'file_uri' => $url,
                ],
            ];
        } else {
            $payload = [
                'inline_data' => [
                    'mime_type' => $this->media->mimeType(),
                    'data' => $this->media->base64(),
                ],
            ];
        }

        if ($mediaResolution = $this->media->providerOptions('mediaResolution')) {
            $payload['media_resolution'] = ['level' => $mediaResolution];
        }

        return $payload;
    }

    protected function provider(): string|Provider
    {
        return Provider::Gemini;
    }

    /**
     * A URL is accepted only when Gemini can take it as a file URI.
     *
     * YouTube links and Gemini File API URIs go to the provider as a reference.
     * Any other URL used to be accepted too, because it was fetched and inlined
     * — through an unguarded request, which is why that fetch is gone. Accepting
     * such a URL now would pass validation and inline `null`.
     */
    protected function validateMedia(): bool
    {
        $url = $this->media->url();

        if ($this->media->isUrl() && $url !== null && MediaUrlDetector::shouldPassAsFileUri($url)) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
