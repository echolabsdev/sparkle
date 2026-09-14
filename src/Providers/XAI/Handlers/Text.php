<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\XAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\XAI\Concerns\ValidatesResponses;
use Prism\Prism\Providers\XAI\Maps\MessageMap;
use Prism\Prism\Providers\XAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\XAI\Maps\ToolMap;
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
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

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

        // Handle potential refusal from XAI
        $this->handleRefusal(data_get($data, 'choices.0.message', []));

        $finishReason = $this->mapFinishReason($data);

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            default => $this->handleStop($data, $request),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolCalls = $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []));

        if ($toolCalls === []) {
            throw new PrismException('XAI: finish reason is tool_calls but no tool calls found in response');
        }

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $toolResults);

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
    protected function handleStop(array $data, Request $request): TextResponse
    {
        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $payload = array_merge([
            'model' => $request->model(),
            'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_tokens' => $request->maxTokens() ?? 2048,
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]));

        /** @var ClientResponse $response */
        $response = $this->client->post('chat/completions', $payload);

        return $response;
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

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, ToolResult>  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', [])),
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
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        ));
    }
}
