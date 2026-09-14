<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Qwen\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Qwen\Concerns\ValidatesResponses;
use Prism\Prism\Providers\Qwen\Maps\MessageMap;
use Prism\Prism\Providers\Qwen\Maps\ToolCallMap;
use Prism\Prism\Providers\Qwen\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Qwen\Maps\ToolMap;
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
            FinishReason::Stop => $this->handleStop($data, $request),
            default => throw new PrismException('Qwen: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'output.choices.0.message.tool_calls', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $toolResults);

        $request = $request->addMessage(new AssistantMessage(
            data_get($data, 'output.choices.0.message.content') ?? '',
            $toolCalls,
            [],
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
        $messageMap = new MessageMap($request->messages(), $request->systemPrompts());

        $input = [
            'messages' => $messageMap(),
        ];

        $parameters = Arr::whereNotNull([
            'result_format' => 'message',
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ToolMap::map($request->tools()) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
     * @param  array<string, mixed>  $data
     * @param  array<int, ToolResult>  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(data_get($data, 'output.choices.0.message.tool_calls', [])),
            toolResults: $toolResults,
            providerToolCalls: [],
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
        ));
    }
}
