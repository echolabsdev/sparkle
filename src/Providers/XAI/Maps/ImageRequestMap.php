<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Request $request): array
    {
        $baseData = [
            'model' => $request->model(),
            'prompt' => $request->prompt(),
        ];

        $providerOptions = $request->providerOptions();

        $supportedOptions = [
            'n' => $providerOptions['n'] ?? null,
            'response_format' => $providerOptions['response_format'] ?? null,
            'aspect_ratio' => $providerOptions['aspect_ratio'] ?? null,
            'resolution' => $providerOptions['resolution'] ?? null,
        ];

        // Include any additional options not explicitly handled above
        $additionalOptions = array_diff_key($providerOptions, $supportedOptions);

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            $additionalOptions
        );
    }
}
