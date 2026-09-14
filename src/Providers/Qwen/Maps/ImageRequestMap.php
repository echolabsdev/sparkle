<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;
use Prism\Prism\ValueObjects\Media\Image;

class ImageRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $parameters = Arr::whereNotNull([
            'size' => $providerOptions['size'] ?? null,
            'n' => $providerOptions['n'] ?? null,
            'negative_prompt' => $providerOptions['negative_prompt'] ?? null,
            'prompt_extend' => $providerOptions['prompt_extend'] ?? null,
            'watermark' => $providerOptions['watermark'] ?? null,
            'seed' => $providerOptions['seed'] ?? null,
        ]);

        // Include any additional options not explicitly handled above
        $knownKeys = ['size', 'n', 'negative_prompt', 'prompt_extend', 'watermark', 'seed'];
        $additionalOptions = array_diff_key($providerOptions, array_flip($knownKeys));

        $parameters = array_merge($parameters, $additionalOptions);

        // Build content array: images first (for image editing), then text prompt
        $content = [];

        foreach ($request->additionalContent() as $image) {
            $content[] = [
                'image' => self::resolveImageSource($image),
            ];
        }

        $content[] = [
            'text' => $request->prompt(),
        ];

        $payload = [
            'model' => $request->model(),
            'input' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content,
                    ],
                ],
            ],
        ];

        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        return $payload;
    }

    /**
     * Resolve an Image to a URL string or base64 data URI for the DashScope API.
     */
    protected static function resolveImageSource(Image $image): string
    {
        $url = $image->url();

        if ($image->isUrl() && $url !== null) {
            return $url;
        }

        return sprintf('data:%s;base64,%s', $image->mimeType(), $image->base64());
    }
}
