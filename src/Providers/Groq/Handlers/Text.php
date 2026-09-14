<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Groq\Concerns\ValidateResponse;
use Prism\Prism\Providers\Groq\Maps\FinishReasonMap;
use Prism\Prism\Providers\Groq\Maps\MessageMap;
use Prism\Prism\Providers\Groq\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Groq\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools, ProcessRateLimits,  ValidateResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $this->resolveToolApprovals($request);

        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $finishReason = FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', ''));

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            default => $this->handleStop($data, $request, $response, $finishReason),
        };
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'chat/completions',
            Arr::whereNotNull([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ])
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): TextResponse
    {
        $toolCalls = $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []);

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $clientResponse, FinishReason::ToolCalls, $toolResults);

        $request->addMessage(new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $toolCalls,
            toolApprovalRequests: $approvalRequests,
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse, FinishReason $finishReason): TextResponse
    {
        $this->addStep($data, $request, $clientResponse, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, ClientResponse $clientResponse, FinishReason $finishReason, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $finishReason,
            toolCalls: $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
            toolResults: $toolResults,
            providerToolCalls: [],
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
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(function (array $toolCall): ToolCall {
            $name = data_get($toolCall, 'function.name', '');
            $arguments = data_get($toolCall, 'function.arguments', '{}');

            // Some Llama models (e.g. llama-3.3-70b-versatile on Groq) embed
            // the arguments JSON directly in the function name field, separated
            // by a comma: "tool_name,{\"arg\":\"val\"}". Groq then rejects the
            // follow-up turn because the mangled name is not in request.tools.
            // Detect this pattern and split the name from the inline arguments.
            if (is_string($name) && str_contains($name, ',{')) {
                [$extractedName, $inlineArgs] = explode(',', $name, 2);
                if (json_decode($inlineArgs) !== null) {
                    $name = $extractedName;
                    $arguments = $inlineArgs;
                }
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: $name,
                arguments: $arguments ?: '{}',
            );
        }, $toolCalls);
    }
}
