<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Azure\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Azure\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Azure\Maps\FinishReasonMap;
use Prism\Prism\Providers\Azure\Maps\MessageMap;
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
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected Azure $provider,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $request = $this->appendMessageForJsonMode($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return $this->createResponse($request, $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        $tokenParameter = $this->tokenParameter($request->model());

        try {
            /** @var Response $response */
            $response = $this->client
                ->throw()
                ->post(
                    'chat/completions',
                    array_merge([
                        'model' => $this->provider->usesV1ForModel($request->model())
                            ? $this->provider->resolveModelIdentifier($request->model())
                            : null,
                        'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                        $tokenParameter => $request->maxTokens(),
                    ], Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                        'response_format' => ['type' => 'json_object'],
                        'reasoning_effort' => $request->providerOptions('reasoning_effort')
                            ?? $request->providerOptions('reasoning.effort'),
                        'verbosity' => $request->providerOptions('verbosity')
                            ?? $request->providerOptions('text_verbosity'),
                    ]))
                );

            return $response->json();
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('Azure Error: Empty response');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createResponse(Request $request, array $data): StructuredResponse
    {
        $text = data_get($data, 'choices.0.message.content') ?? '';

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $step = new Step(
            text: $text,
            finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
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
        );

        $this->responseBuilder->addStep($step);

        return $this->responseBuilder->toResponse();
    }

    protected function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema. \n Do NOT explain or add other content. Validate your response against this schema \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    protected function tokenParameter(string $model): string
    {
        return str_contains(mb_strtolower($model), 'gpt-5')
            ? 'max_completion_tokens'
            : 'max_tokens';
    }
}
