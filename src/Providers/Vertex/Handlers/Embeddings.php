<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    public function __construct(
        protected PendingRequest $client,
        protected string $model,
    ) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        if (count($request->inputs()) > 1) {
            throw new PrismException('Vertex Error: Prism currently only supports one input at a time with Vertex AI.');
        }

        $response = $this->sendRequest($request);

        $data = $response->json();

        if (! isset($data['predictions'][0]['embeddings']['values'])) {
            throw PrismException::providerResponseError(
                'Vertex Error: Invalid response format or missing embedding data'
            );
        }

        return new EmbeddingsResponse(
            embeddings: [Embedding::fromArray(data_get($data, 'predictions.0.embeddings.values', []))],
            usage: new EmbeddingsUsage(
                data_get($data, 'metadata.billableCharacterCount', 0)
            ),
            meta: new Meta(
                id: '',
                model: $this->model,
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        /** @var Response $response */
        $response = $this->client->post(
            "{$this->model}:predict",
            Arr::whereNotNull([
                'instances' => [
                    [
                        'content' => $request->inputs()[0],
                    ],
                ],
                'parameters' => Arr::whereNotNull([
                    'outputDimensionality' => $providerOptions['outputDimensionality'] ?? null,
                ]) ?: null,
            ])
        );

        return $response;
    }
}
