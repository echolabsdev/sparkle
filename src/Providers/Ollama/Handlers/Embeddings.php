<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

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
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);
        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(sprintf(
                'Ollama Error: %s',
                data_get($data, 'error', 'unknown'),
            ));
        }

        return new EmbeddingsResponse(
            embeddings: array_map(Embedding::fromArray(...), data_get($data, 'embeddings', [])),
            usage: new EmbeddingsUsage(data_get($data, 'prompt_eval_count')),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        $options = Arr::except($request->providerOptions(), ['dimensions', 'keep_alive']);

        /** @var Response $response */
        $response = $this->client->post(
            'api/embed',
            Arr::whereNotNull([
                'model' => $request->model(),
                'input' => $request->inputs(),
                'dimensions' => $request->providerOptions('dimensions'),
                'keep_alive' => $request->providerOptions('keep_alive'),
                'options' => $options ?: null,
            ])
        );

        return $response;
    }
}
