<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\Qwen\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            meta: new Meta(
                id: data_get($data, 'request_id', ''),
                model: $request->model(),
            ),
            images: $images,
            additionalContent: Arr::whereNotNull([
                'image_count' => data_get($data, 'usage.image_count'),
                'width' => data_get($data, 'usage.width'),
                'height' => data_get($data, 'usage.height'),
            ]),
            raw: $data,
        );

        return $responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'services/aigc/multimodal-generation/generation',
            ImageRequestMap::map($request)
        );

        return $response;
    }

    protected function validateResponse(ClientResponse $response): void
    {
        if (! $response->successful()) {
            $data = $response->json() ?? [];
            $errorMessage = data_get($data, 'message', $response->body());
            $errorCode = data_get($data, 'code', 'Unknown');

            throw PrismException::providerResponseError(
                sprintf('Qwen Image generation failed [%s]: %s', $errorCode, $errorMessage)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $images = [];

        $choices = data_get($data, 'output.choices', []);

        foreach ($choices as $choice) {
            $content = data_get($choice, 'message.content', []);

            foreach ($content as $item) {
                if (isset($item['image'])) {
                    $images[] = new GeneratedImage(
                        url: $item['image'],
                    );
                }
            }
        }

        return $images;
    }
}
