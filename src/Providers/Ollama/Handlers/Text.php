<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
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
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $this->resolveToolApprovals($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        // Check for tool calls first, regardless of finish reason
        if (! empty(data_get($data, 'message.tool_calls'))) {
            return $this->handleToolCalls($data, $request);
        }

        return $this->handleStop($data, $request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this
            ->client
            ->post('api/chat', [
                'model' => $request->model(),
                'messages' => (new MessageMap(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )))->map(),
                'tools' => ToolMap::map($request->tools()),
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): Response
    {
        $toolCalls = $this->mapToolCalls(data_get($data, 'message.tool_calls', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $toolResults);

        $request->addMessage(new AssistantMessage(
            data_get($data, 'message.content') ?? '',
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
    protected function handleStop(array $data, Request $request): Response
    {
        $this->addStep($data, $request);

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
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: $this->mapToolCalls(data_get($data, 'message.tool_calls', []) ?? []),
            toolResults: $toolResults,
            providerToolCalls: [],
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
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
