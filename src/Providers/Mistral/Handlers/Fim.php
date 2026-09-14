<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Fim\Request;
use Prism\Prism\Fim\Response;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Fim
{
    use ProcessRateLimits;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new Response(
            text: data_get($data, 'choices.0.message.content', ''),
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens', 0),
                data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
                rateLimits: $this->processRateLimits($response),
            )
        );
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'fim/completions',
            array_merge([
                'model' => $request->model(),
                'prompt' => $request->prompt(),
            ], Arr::whereNotNull([
                'suffix' => $request->suffix(),
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'stop' => $request->stop() === [] ? null : $request->stop(),
            ]))
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return match (data_get($data, 'choices.0.finish_reason')) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
