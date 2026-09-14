<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Azure\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Azure\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Azure\Maps\MessageMap;
use Prism\Prism\Providers\Azure\Maps\ToolCallMap;
use Prism\Prism\Providers\Azure\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Azure\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected Azure $provider,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $this->resolveToolApprovals($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $responseMessage = new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            []
        );

        $request = $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop => $this->handleStop($data, $request),
            FinishReason::Length => throw new PrismException('Azure: max tokens exceeded'),
            FinishReason::ContentFilter => throw new PrismException('Azure: content filter triggered'),
            default => throw new PrismException('Azure: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        if ($approvalRequests !== []) {
            // Replace the assistant message appended in handle() with one
            // carrying the approval requests so the resume pass correlates them.
            $messages = $request->messages();
            array_pop($messages);
            $request->setMessages($messages);
            $request->addMessage(new AssistantMessage(
                data_get($data, 'choices.0.message.content') ?? '',
                $toolCalls,
                toolApprovalRequests: $approvalRequests,
            ));
        }

        $request = $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $toolResults);

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
                        'tools' => ToolMap::map($request->tools()) ?: null,
                        'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
     * @param  array<int, ToolResult>  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
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
        ));
    }

    protected function tokenParameter(string $model): string
    {
        return str_contains(mb_strtolower($model), 'gpt-5')
            ? 'max_completion_tokens'
            : 'max_tokens';
    }
}
