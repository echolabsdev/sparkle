<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\CitationMapper;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolCallMap;
use Prism\Prism\Providers\Gemini\Maps\ToolConfigMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools, ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $this->resolveToolApprovals($request);

        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $isToolCall = $this->hasToolCalls($data);

        $finishReason = FinishReasonMap::map(
            data_get($data, 'candidates.0.finishReason'),
            $isToolCall
        );

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            default => $this->handleStop($data, $request, $finishReason),
        };
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $providerOptions = $request->providerOptions();

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = Arr::whereNotNull([
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => $providerOptions['includeThoughts'] ?? null,
            ]);
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = Arr::whereNotNull([
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => $providerOptions['includeThoughts'] ?? null,
            ]);
        }

        if ($request->reasoningEnabled() === false && $thinkingConfig === null) {
            $thinkingConfig = ['thinkingBudget' => 0];
        }

        $generationConfig = Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'topP' => $request->topP(),
            'topK' => $request->topK(),
            'maxOutputTokens' => $request->maxTokens(),
            'thinkingConfig' => $thinkingConfig,
        ]);

        $hasBothToolTypes = $request->tools() !== [] && $request->providerTools() !== [];

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = array_map(
                fn (ProviderTool $providerTool): array => [
                    $providerTool->type => $providerTool->optionsAsObject(),
                ],
                $request->providerTools()
            );
        }

        if ($request->tools() !== []) {
            $tools[] = ['function_declarations' => ToolMap::map($request->tools())];
        }

        $toolConfig = ToolConfigMap::map($request->toolChoice(), $hasBothToolTypes);

        /** @var ClientResponse $response */
        $response = $this->client->post(
            "{$request->model()}:generateContent",
            Arr::whereNotNull([
                ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
                'tools' => $tools !== [] ? $tools : null,
                'tool_config' => $toolConfig,
                'safetySettings' => $providerOptions['safetySettings'] ?? null,
                'service_tier' => $providerOptions['serviceTier'] ?? null,
            ])
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, FinishReason $finishReason): TextResponse
    {
        $this->addStep($data, $request, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'candidates.0.content.parts', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, FinishReason::ToolCalls, $toolResults);

        $request->addMessage(new AssistantMessage(
            $this->extractTextContent($data),
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

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, FinishReason $finishReason, array $toolResults = []): void
    {
        $thoughtSummaries = $this->extractThoughtSummaries($data);

        $this->responseBuilder->addStep(new Step(
            text: $this->extractTextContent($data),
            finishReason: $finishReason,
            toolCalls: $finishReason === FinishReason::ToolCalls ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: new Usage(
                promptTokens: max(0, (int) data_get($data, 'usageMetadata.promptTokenCount', 0) - (int) data_get($data, 'usageMetadata.cachedContentTokenCount', 0)),
                completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
                thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'modelVersion', ''),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: Arr::whereNotNull([
                'citations' => CitationMapper::mapFromGemini(data_get($data, 'candidates.0', [])) ?: null,
                'searchEntryPoint' => data_get($data, 'candidates.0.groundingMetadata.searchEntryPoint'),
                'searchQueries' => data_get($data, 'candidates.0.groundingMetadata.webSearchQueries'),
                'urlMetadata' => data_get($data, 'candidates.0.urlContextMetadata.urlMetadata'),
                'thoughtSummaries' => $thoughtSummaries !== [] ? $thoughtSummaries : null,
            ]),
            raw: $data,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractTextContent(array $data): string
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);
        $textParts = [];

        foreach ($parts as $part) {
            // Only include text from parts that are NOT thoughts
            if (isset($part['text']) && (! isset($part['thought']) || $part['thought'] === false)) {
                $textParts[] = $part['text'];
            }
        }

        return implode('', $textParts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function extractThoughtSummaries(array $data): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);
        $thoughtSummaries = [];

        foreach ($parts as $part) {
            // Collect text from parts marked as thoughts
            if (isset($part['thought']) && $part['thought'] === true && isset($part['text'])) {
                $thoughtSummaries[] = $part['text'];
            }
        }

        return $thoughtSummaries;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }
}
