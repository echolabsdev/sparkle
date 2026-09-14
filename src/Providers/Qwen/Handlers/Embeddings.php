<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Qwen\Concerns\ValidatesResponses;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use ValidatesResponses;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);

        $data = $response->json();

        $this->validateResponse($data);

        return new EmbeddingsResponse(
            embeddings: array_map(
                fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
                data_get($data, 'output.embeddings', [])
            ),
            usage: new EmbeddingsUsage(data_get($data, 'usage.total_tokens')),
            meta: new Meta(
                id: data_get($data, 'request_id', ''),
                model: $request->model(),
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        $payload = [
            'model' => $request->model(),
            'input' => [
                'texts' => $request->inputs(),
            ],
        ];

        $providerOptions = $request->providerOptions() ?? [];
        if ($providerOptions !== []) {
            $payload['parameters'] = $providerOptions;
        }

        /** @var Response $response */
        $response = $this->client->post(
            'services/embeddings/text-embedding/text-embedding',
            $payload
        );

        return $response;
    }
}
