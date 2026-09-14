<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    /** @return array<string, mixed> */
    public static function map(Request $request): array
    {
        return match (str_contains($request->model(), 'gemini')) {
            true => self::geminiOptions($request),
            false => self::imagenOptions($request),
        };
    }

    /** @return array<string, mixed> */
    protected static function geminiOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $parts = [];

        // Add images first (Gemini best practice for multimodal prompts)
        foreach ($request->additionalContent() as $image) {
            // Inlined unconditionally, and this map is not a ProviderMediaMapper,
            // so no validateMedia() ever ran here. A URL input image used to be
            // fetched and inlined; with that fetch gone it would inline `null`
            // without a word. Refused instead, with the same way out.
            if ($image->isUrl() && ! $image->hasRawContent()) {
                throw new PrismException(
                    'Gemini image generation needs input image bytes, and this image was built from a URL. Prism '
                    .'no longer fetches a URL implicitly. If you trust this URL, call fetchUrlContent() on the image '
                    .'before sending it.'
                );
            }

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $image->mimeType(),
                    'data' => $image->base64(),
                ],
            ];
        }

        // Add text prompt after images
        $parts[] = [
            'text' => $request->prompt(),
        ];

        $result = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => $providerOptions['response_modalities'] ?? ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => $providerOptions['aspect_ratio'] ?? null,
                    'imageSize' => $providerOptions['image_size'] ?? null,
                ],
            ],
        ];

        if (isset($providerOptions['safety_settings'])) {
            $result['safetySettings'] = $providerOptions['safety_settings'];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    protected static function imagenOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $options = [
            'instances' => [
                [
                    'prompt' => $request->prompt(),
                ],
            ],
        ];

        $parameters = Arr::whereNotNull([
            'sampleCount' => $providerOptions['n'] ?? null,
            'sampleImageSize' => $providerOptions['size'] ?? null,
            'aspectRatio' => $providerOptions['aspect_ratio'] ?? null,
            'personGeneration' => $providerOptions['person_generation'] ?? null,
        ]);

        if (! empty($parameters)) {
            $options['parameters'] = $parameters;
        }

        return $options;
    }
}
