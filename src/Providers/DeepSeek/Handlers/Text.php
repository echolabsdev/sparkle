<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\DeepSeek\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\DeepSeek\Concerns\MapsFinishReason;
use Prism\Prism\Providers\DeepSeek\Concerns\ValidatesResponses;
use Prism\Prism\Providers\DeepSeek\Maps\MessageMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolCallMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolChoiceMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
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

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $this->resolveToolApprovals($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            default => $this->handleStop($data, $request),
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

        $this->addStep($data, $request, $toolResults);

        $reasoningContent = data_get($data, 'choices.0.message.reasoning_content');
        $additionalContent = $reasoningContent ? ['reasoning_content' => $reasoningContent] : [];

        $request = $request->addMessage(new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $toolCalls,
            $additionalContent,
            toolApprovalRequests: $approvalRequests,
        ));
        $request = $request->addMessage(new ToolResultMessage($toolResults));
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

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge(
                // DeepSeek-specific knobs — 'thinking' => ['type' => 'disabled'],
                // 'reasoning_effort', 'stop', the penalties, and anything DeepSeek
                // adds later. Merged first so the explicit request settings below
                // always win and a stray option cannot clobber model or messages.
                $request->providerOptions(),
                [
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()) ?: null,
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                ])
            )
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, ToolResult>  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $totalPrompt = (int) (data_get($data, 'usage.prompt_tokens') ?? 0);
        $cacheHit = (int) (data_get($data, 'usage.prompt_cache_hit_tokens') ?? 0);
        $reasoning = (int) (data_get($data, 'usage.completion_tokens_details.reasoning_tokens') ?? 0);
        $reasoningContent = data_get($data, 'choices.0.message.reasoning_content');

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: new Usage(
                promptTokens: max(0, $totalPrompt - $cacheHit),
                completionTokens: (int) (data_get($data, 'usage.completion_tokens') ?? 0),
                cacheReadInputTokens: $cacheHit > 0 ? $cacheHit : null,
                thoughtTokens: $reasoning > 0 ? $reasoning : null,
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $reasoningContent ? ['reasoning_content' => $reasoningContent] : [],
            raw: $data,
        ));
    }
}
