<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use HandlesPredictions;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60
    ) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $embeddings = [];
        $totalTokens = 0;

        // Process each input separately
        foreach ($request->inputs() as $input) {
            $payload = [
                'version' => $this->extractVersionFromModel($request->model()),
                'input' => array_merge(
                    ['text' => $input],
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

            // Extract embedding from output
            $vectors = $completedPrediction->output['vectors'] ?? [];
            if (! empty($vectors)) {
                $embeddings[] = Embedding::fromArray($vectors);
                $totalTokens += $this->estimateTokens($input);
            }
        }

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            usage: new EmbeddingsUsage($totalTokens),
            meta: new Meta(
                id: '',
                model: $request->model(),
            ),
        );
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
     * Estimate tokens for usage tracking.
     * Rough approximation: ~4 characters per token.
     */
    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Extract version from model string.
     * Supports formats like "owner/model:version" or just "owner/model".
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Return as-is and let Replicate use the latest version or resolve the format
        return $model;
    }
}
