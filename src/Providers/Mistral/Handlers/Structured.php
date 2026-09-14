<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ExtractsText;
use Prism\Prism\Providers\Mistral\Concerns\ExtractsThinking;
use Prism\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Mistral\Maps\MessageMap;
use Prism\Prism\Providers\Mistral\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Mistral\Maps\ToolMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools;
    use ExtractsText;
    use ExtractsThinking;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $this->resolveToolApprovals($request);

        return $this->sendAndRespond($request);
    }

    /**
     * Send the request and handle the response, looping on tool calls.
     *
     * Mistral does not allow response_format and tools in the same request.
     * When tools are present, we omit response_format and let the model call
     * tools freely. Once tool calling is done, we re-send without tools but
     * with response_format to get the final structured JSON response.
     */
    protected function sendAndRespond(Request $request): StructuredResponse
    {
        $hasTools = count($request->tools()) > 0;

        $response = $this->sendRequest($request, useTools: $hasTools);

        $this->validateResponse($response);

        $data = $response->json();

        if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
            return $this->handleToolCalls($data, $request, $response);
        }

        // If tools were sent but the model chose to stop without calling them,
        // the response is unconstrained text (no response_format was set).
        // Discard it and re-send without tools to get proper structured output.
        if ($hasTools) {
            return $this->sendStructuredResponse($request);
        }

        return $this->handleStop($data, $request, $response);
    }

    /**
     * Send a final request without tools but with json_schema response format
     * to get the structured JSON output.
     */
    protected function sendStructuredResponse(Request $request): StructuredResponse
    {
        $response = $this->sendRequest($request, useTools: false);

        $this->validateResponse($response);

        return $this->handleStop($response->json(), $request, $response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $toolCalls = $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $clientResponse, toolCalls: $toolCalls, toolResults: $toolResults);

        $request->addMessage(new AssistantMessage(
            $this->extractText(data_get($data, 'choices.0.message', [])),
            $toolCalls,
            toolApprovalRequests: $approvalRequests,
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->sendAndRespond($request);
        }

        // Max steps exhausted during tool calling. Send one final request
        // without tools to get structured output.
        return $this->sendStructuredResponse($request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $text = data_get($data, 'choices.0.message.content') ?? '';

        $request->addMessage(new AssistantMessage($text));

        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        if ($request->maxSteps() === 0) {
            return true;
        }

        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        array $toolCalls = [],
        array $toolResults = [],
    ): void {
        $this->responseBuilder->addStep(new Step(
            text: $this->extractText(data_get($data, 'choices.0.message', [])),
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->extractThinking(data_get($data, 'choices.0.message', [])),
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            raw: $data,
        ));
    }

    /**
     * Send the request to Mistral, using either tools or json_schema response format.
     *
     * Uses json_schema mode (strict server-side schema enforcement) instead of
     * json_object mode, so Mistral enforces the output schema directly rather
     * than relying on a system prompt instruction.
     */
    protected function sendRequest(Request $request, bool $useTools = false): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'reasoning_effort' => $request->providerOptions('reasoning_effort'),
                'tools' => $useTools ? ToolMap::map($request->tools()) : null,
                'tool_choice' => $useTools ? ToolChoiceMap::map($request->toolChoice()) : null,
                'response_format' => $useTools ? null : [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'schema' => $request->schema()->toArray(),
                        'name' => $request->schema()->name(),
                        'strict' => true,
                    ],
                ],
            ]))
        );

        return $response;
    }

    /**
     * @param  array<mixed>|null  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(?array $toolCalls): array
    {
        if (! $toolCalls) {
            return [];
        }

        return array_map(fn ($toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
