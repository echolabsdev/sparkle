<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use MapsFinishReason;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $responseMessage = new AssistantMessage(
            data_get($data, 'message.content') ?? '',
        );

        $request->addMessage($responseMessage);

        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                data_get($data, 'prompt_eval_count', 0),
                data_get($data, 'eval_count', 0),
            ),
            meta: new Meta(
                id: '',
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post('api/chat', [
            'model' => $request->model(),
            'messages' => (new MessageMap(array_merge(
                $request->systemPrompts(),
                $request->messages()
            )))->map(),
            'format' => $request->schema()->toArray(),
            'stream' => false,
            ...Arr::whereNotNull([
                'think' => $request->providerOptions('thinking') ?? (
                    $request->reasoningEnabled() === false ? false : null
                ),
                'keep_alive' => $request->providerOptions('keep_alive'),
            ]),
            'options' => Arr::whereNotNull(array_merge([
                'temperature' => $request->temperature(),
                'num_predict' => $request->maxTokens() ?? 2048,
                'top_p' => $request->topP(),
            ], $request->providerOptions())),
        ]);

        return $response->json();
    }
}
