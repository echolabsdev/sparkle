<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Z\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Z\Maps\StructuredMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use MapsFinishReason;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = $this->sendRequest($request);

        $data = $response->json();

        $content = data_get($data, 'choices.0.message.content');

        $responseMessage = new AssistantMessage($content);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $structured = new StructuredMap($request->messages(), $request->systemPrompts(), $request->schema());

        $payload = array_merge([
            'model' => $request->model(),
            'messages' => $structured(),
            'response_format' => [
                'type' => 'json_object',
            ],
            'thinking' => [
                'type' => 'disabled',
            ],
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
        ]));

        /** @var ClientResponse $response */
        $response = $this->client->post('chat/completions', $payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                promptTokens: max(0, (int) data_get($data, 'usage.prompt_tokens', 0) - (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0)),
                completionTokens: (int) data_get($data, 'usage.completion_tokens', 0),
                cacheReadInputTokens: (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0) ?: null,
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            structured: [],
        ));
    }
}
