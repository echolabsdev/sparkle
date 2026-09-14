<?php

namespace Prism\Prism\Providers\Requesty\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Requesty\Concerns\ValidatesResponses;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use ValidatesResponses;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => Embedding::fromArray($item['embedding']), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(data_get($data, 'usage.total_tokens')),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
            ),
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'embeddings',
            [
                'model' => $request->model(),
                'input' => $request->inputs(),
                ...($request->providerOptions() ?? []),
            ]
        );

        return $response->json();
    }
}
