<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;
use Prism\Prism\Providers\Azure\Azure;

class ImageRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Request $request, Azure $provider): array
    {
        $baseData = Arr::whereNotNull([
            'prompt' => $request->prompt(),
        ]);

        // Include model in payload for v1 endpoints
        if ($provider->usesV1ForModel($request->model())) {
            $baseData['model'] = $request->model();
        }

        $providerOptions = $request->providerOptions();

        $supportedOptions = Arr::whereNotNull([
            'n' => $providerOptions['n'] ?? null,
            'size' => $providerOptions['size'] ?? null,
            'response_format' => $providerOptions['response_format'] ?? null,
            'quality' => $providerOptions['quality'] ?? null,
            'style' => $providerOptions['style'] ?? null,
            'output_format' => $providerOptions['output_format'] ?? null,
        ]);

        // Include any additional provider options not explicitly handled
        $additionalOptions = array_diff_key($providerOptions, $supportedOptions);

        return array_merge($baseData, $supportedOptions, $additionalOptions);
    }
}
