<?php

namespace Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\ChatCompletionsFinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\ChatCompletionsMessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use ProcessRateLimits, ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $request = $this->appendMessageForJsonMode($request);

        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return $this->createResponse($request, $data, $response);
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new ChatCompletionsMessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'response_format' => ['type' => 'json_object'],
                'verbosity' => $request->providerOptions('text_verbosity'),
            ]))
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createResponse(Request $request, array $data, ClientResponse $clientResponse): StructuredResponse
    {
        $text = data_get($data, 'choices.0.message.content') ?? '';

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $step = new Step(
            text: $text,
            finishReason: ChatCompletionsFinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
            usage: new Usage(
                promptTokens: max(0, (int) data_get($data, 'usage.prompt_tokens', 0) - (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0)),
                completionTokens: (int) data_get($data, 'usage.completion_tokens', 0),
                cacheReadInputTokens: (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0) ?: null,
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        );

        $this->responseBuilder->addStep($step);

        return $this->responseBuilder->toResponse();
    }

    protected function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "Respond with JSON that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
