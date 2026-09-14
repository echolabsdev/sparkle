<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Azure\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    public function __construct(
        protected PendingRequest $client,
        protected Azure $provider,
    ) {}

    public function handle(Request $request): Response
    {
        $payload = ImageRequestMap::map($request, $this->provider);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post('images/generations', $payload);

        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Azure Image Error: [%s] %s',
                [
                    data_get($data, 'error.code', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }

        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', 'img_'.bin2hex(random_bytes(8))),
                model: data_get($data, 'model', $request->model()),
                rateLimits: [],
            ),
            images: $images,
        );

        return $responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $images = [];

        foreach (data_get($data, 'data', []) as $imageData) {
            $images[] = new GeneratedImage(
                url: data_get($imageData, 'url'),
                base64: data_get($imageData, 'b64_json'),
                revisedPrompt: data_get($imageData, 'revised_prompt'),
            );
        }

        return $images;
    }
}
