<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\XAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\XAI\Concerns\ValidatesResponses;
use Prism\Prism\Providers\XAI\Maps\MessageMap;
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
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $this->handleRefusal(data_get($data, 'choices.0.message', []));

        $rawContent = data_get($data, 'choices.0.message.content') ?? '';
        $parsed = data_get($data, 'choices.0.message.parsed');

        // Some OpenAI-compatible providers (e.g. Cloudflare Workers AI) return
        // content as a parsed object/array instead of a JSON string. Normalize
        // to string for AssistantMessage and extract structured data.
        $content = is_array($rawContent) ? json_encode($rawContent) : $rawContent;
        $parsed ??= is_array($rawContent) ? $rawContent : null;

        $responseMessage = new AssistantMessage($content);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request, $parsed);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $parsed
     */
    protected function addStep(array $data, Request $request, ?array $parsed): void
    {
        $rawContent = data_get($data, 'choices.0.message.content') ?? '';

        $this->responseBuilder->addStep(new Step(
            text: is_array($rawContent) ? json_encode($rawContent) : $rawContent,
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
            structured: $parsed ?? [],
            raw: $data,
        ));
    }

    protected function sendRequest(Request $request): ClientResponse
    {

        $responseFormat = $this->buildResponseFormat($request);

        /** @var ClientResponse $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens() ?? 2048,
                'response_format' => $responseFormat,
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
            ]))
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildResponseFormat(Request $request): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => Arr::whereNotNull([
                'name' => $request->schema()->name(),
                'schema' => $request->schema()->toArray(),
                'strict' => $request->providerOptions('schema.strict') ? true : null,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function handleRefusal(array $message): void
    {
        if (data_get($message, 'refusal') !== null) {
            throw new PrismException(sprintf('XAI Refusal: %s', $message['refusal']));
        }
    }
}
