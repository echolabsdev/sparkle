<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Providers\Qwen\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Qwen\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Qwen\Maps\FinishReasonMap;
use Prism\Prism\Providers\Qwen\Maps\MessageMap;
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

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $mode = $request->mode();

        // Structured mode: use response_format with json_schema (no system message needed)
        // Auto/Json mode: use response_format with json_object + system message with schema
        if ($mode !== StructuredMode::Structured) {
            $request = $this->appendMessageForJsonMode($request);
        }

        $data = $this->sendRequest($request, $mode);

        $this->validateResponse($data);

        return $this->createResponse($request, $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request, StructuredMode $mode): array
    {
        $messageMap = new MessageMap($request->messages(), $request->systemPrompts());

        $input = [
            'messages' => $messageMap(),
        ];

        $parameters = Arr::whereNotNull([
            'result_format' => 'message',
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'response_format' => $this->buildResponseFormat($request, $mode),
        ]);

        $payload = [
            'model' => $request->model(),
            'input' => $input,
        ];

        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        // VL (multimodal) models use the multimodal-generation endpoint
        $endpoint = $messageMap->hasImages()
            ? 'services/aigc/multimodal-generation/generation'
            : 'services/aigc/text-generation/generation';

        /** @var Response $response */
        $response = $this->client->post($endpoint, $payload);

        return $response->json();
    }

    /**
     * Build the response_format parameter based on the structured mode.
     *
     * - Structured mode: {"type": "json_schema", "json_schema": {"name": ..., "schema": ..., "strict": true}}
     * - Auto/Json mode: {"type": "json_object"}
     *
     * @return array<string, mixed>
     */
    protected function buildResponseFormat(Request $request, StructuredMode $mode): array
    {
        if ($mode === StructuredMode::Structured) {
            return [
                'type' => 'json_schema',
                'json_schema' => Arr::whereNotNull([
                    'name' => $request->schema()->name(),
                    'schema' => $request->schema()->toArray(),
                    'strict' => $request->providerOptions('schema.strict') ?? true,
                ]),
            ];
        }

        return ['type' => 'json_object'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createResponse(Request $request, array $data): StructuredResponse
    {
        $text = data_get($data, 'output.choices.0.message.content') ?? '';

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $step = new Step(
            text: $text,
            finishReason: FinishReasonMap::map(data_get($data, 'output.choices.0.finish_reason', '')),
            usage: new Usage(
                promptTokens: max(0, (int) data_get($data, 'usage.input_tokens', 0) - (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0)),
                completionTokens: (int) data_get($data, 'usage.output_tokens', 0),
                cacheReadInputTokens: (int) data_get($data, 'usage.prompt_tokens_details.cached_tokens', 0) ?: null,
            ),
            meta: new Meta(
                id: data_get($data, 'request_id'),
                model: $request->model(),
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
            "You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema. \n Do NOT explain or add other content. Validate your response against this schema \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
