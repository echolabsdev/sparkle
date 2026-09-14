<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\ValueObjects\ReplicatePrediction;

trait HandlesPredictions
{
    /**
     * Create a new prediction on Replicate.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $wait  Whether to use sync mode (Prefer: wait header)
     * @param  int  $waitTimeout  Timeout in seconds for sync mode (max 60)
     *
     * @throws RequestException
     */
    protected function createPrediction(
        PendingRequest $client,
        array $payload,
        bool $wait = false,
        int $waitTimeout = 60
    ): ReplicatePrediction {
        // If sync mode is enabled, add the Prefer: wait header
        if ($wait) {
            // Replicate allows max 60 seconds for the Prefer: wait header
            $timeout = min($waitTimeout, 60);
            $client = $client->withHeaders([
                'Prefer' => "wait={$timeout}",
            ]);
        }

        $response = $client->post('/predictions', $payload);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }

    /**
     * Get the status of a prediction.
     *
     * @throws RequestException
     */
    protected function getPrediction(PendingRequest $client, string $predictionId): ReplicatePrediction
    {
        $response = $client->get("/predictions/{$predictionId}");

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }

    /**
     * Wait for a prediction to complete by polling.
     *
     * @throws PrismException
     */
    protected function waitForPrediction(
        PendingRequest $client,
        string $predictionId,
        int $pollingInterval = 1000,
        int $maxWaitTime = 60
    ): ReplicatePrediction {
        $startTime = time();
        $maxWaitSeconds = $maxWaitTime;

        while (true) {
            $prediction = $this->getPrediction($client, $predictionId);

            if ($prediction->isComplete()) {
                return $prediction;
            }

            if (time() - $startTime > $maxWaitSeconds) {
                throw new PrismException(
                    "Replicate: prediction timed out after {$maxWaitSeconds} seconds"
                );
            }

            // Convert milliseconds to microseconds for usleep
            usleep($pollingInterval * 1000);
        }
    }

    /**
     * Cancel a prediction.
     *
     * @throws RequestException
     */
    protected function cancelPrediction(PendingRequest $client, string $predictionId): ReplicatePrediction
    {
        $response = $client->post("/predictions/{$predictionId}/cancel");

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }

    /**
     * Create a prediction and wait for completion.
     * Uses sync mode (Prefer: wait) if enabled, otherwise polls.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $useSyncMode  Whether to use Prefer: wait header
     *
     * @throws RequestException
     * @throws PrismException
     */
    protected function createAndWaitForPrediction(
        PendingRequest $client,
        array $payload,
        bool $useSyncMode = true,
        int $pollingInterval = 1000,
        int $maxWaitTime = 60
    ): ReplicatePrediction {
        if ($useSyncMode) {
            // Use sync mode: Prefer: wait header
            $prediction = $this->createPrediction($client, $payload, wait: true, waitTimeout: $maxWaitTime);

            // If prediction is still not complete (timed out), fall back to polling
            if (! $prediction->isComplete()) {
                return $this->waitForPrediction($client, $prediction->id, $pollingInterval, $maxWaitTime);
            }

            return $prediction;
        }

        // Use async mode: create then poll
        $prediction = $this->createPrediction($client, $payload);

        return $this->waitForPrediction($client, $prediction->id, $pollingInterval, $maxWaitTime);
    }
}
