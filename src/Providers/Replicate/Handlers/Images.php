<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    use HandlesPredictions;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60
    ) {}

    public function handle(Request $request): Response
    {
        // Prepare the prediction payload
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => array_merge(
                ['prompt' => $request->prompt()],
                $this->buildInputParameters($request)
            ),
        ];

        // Create prediction and wait for completion (uses sync mode if enabled)
        $completedPrediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Extract images from output
        $images = $this->extractImages($completedPrediction->output ?? []);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: 0, // Replicate doesn't provide token usage for image generation
                completionTokens: 0,
            ),
            meta: new Meta(
                id: $completedPrediction->id,
                model: $request->model(),
            ),
            images: $images,
        );

        return $responseBuilder->toResponse();
    }

    /**
     * Build input parameters from request.
     *
     * @return array<string, mixed>
     */
    protected function buildInputParameters(Request $request): array
    {
        $params = [];

        // Map provider options
        foreach ($request->providerOptions() as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Extract images from prediction output.
     *
     * @return GeneratedImage[]
     */
    protected function extractImages(mixed $output): array
    {
        $images = [];

        // Replicate returns either a single URL string or an array of URLs
        if (is_string($output)) {
            $output = [$output];
        }

        if (! is_array($output)) {
            return $images;
        }

        foreach ($output as $imageUrl) {
            if (is_string($imageUrl)) {
                // Download the image and convert to base64
                $base64 = $this->downloadImageAsBase64($imageUrl);

                $images[] = new GeneratedImage(
                    url: $imageUrl,
                    base64: $base64, // Replicate doesn't provide revised prompts
                );
            }
        }

        return $images;
    }

    /**
     * Maximum bytes accepted when downloading a generated image.
     */
    protected const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    /**
     * Download an image from URL and convert to base64.
     *
     * The URL originates from the provider response, so it is only fetched
     * when it is https on an allowlisted host (defense against SSRF via a
     * compromised provider path), with an explicit timeout and a size cap.
     * On any rejection or failure the caller falls back to the URL.
     */
    protected function downloadImageAsBase64(string $url): ?string
    {
        if (! $this->isDownloadableUrl($url)) {
            return null;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(30)->get($url);

            if ($response->successful() && strlen($response->body()) <= self::MAX_DOWNLOAD_BYTES) {
                return base64_encode($response->body());
            }
        } catch (\Exception) {
            // If download fails, return null and rely on URL
        }

        return null;
    }

    protected function isDownloadableUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        /** @var array<int, string> $allowedHosts */
        $allowedHosts = config('prism.providers.replicate.download_hosts', ['replicate.delivery']);

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract version from model string.
     */
    /**
     * Extract version ID from model string.
     * Supports formats like "owner/model:version" or just "owner/model".
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Return as-is and let Replicate use the latest version or resolve the format
        return $model;
    }
}
