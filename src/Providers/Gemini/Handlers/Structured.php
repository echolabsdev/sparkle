<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Concerns\HandlesStructuredJson;
use Prism\Prism\Concerns\ManagesStructuredSteps;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\CitationMapper;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Providers\Gemini\Maps\ToolCallMap;
use Prism\Prism\Providers\Gemini\Maps\ToolConfigMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools;
    use HandlesStructuredJson;
    use ManagesStructuredSteps;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $this->resolveToolApprovals($request);

        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $isToolCall = $this->hasToolCalls($data);

        $responseMessage = new AssistantMessage(
            $this->extractTextContent($data),
            $isToolCall ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
        );

        $request->addMessage($responseMessage);

        $finishReason = FinishReasonMap::map(
            data_get($data, 'candidates.0.finishReason'),
            $isToolCall
        );

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            default => $this->handleStop($data, $request, $finishReason),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function sendRequest(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $hasBothToolTypes = $request->tools() !== [] && $request->providerTools() !== [];

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = [
                Arr::mapWithKeys(
                    $request->providerTools(),
                    fn (ProviderTool $providerTool): array => [
                        $providerTool->type => $providerTool->optionsAsObject(),
                    ]
                ),
            ];
        }

        if ($request->tools() !== []) {
            $tools[] = [
                'function_declarations' => ToolMap::map($request->tools()),
            ];
        }

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

        $toolConfig = ToolConfigMap::map($request->toolChoice(), $hasBothToolTypes);

        /** @var Response $response */
        $response = $this->client->post(
            "{$request->model()}:generateContent",
            Arr::whereNotNull([
                ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                'generationConfig' => Arr::whereNotNull([
                    'response_mime_type' => 'application/json',
                    // response_json_schema takes standard JSON Schema, which the
                    // Prism schema objects already emit natively (type arrays for
                    // nullability, null in nullable enums) — no OpenAPI-style
                    // SchemaMap conversion, which would lose nullable semantics.
                    'response_json_schema' => $request->schema()->toArray(),
                    'temperature' => $request->temperature(),
                    'topP' => $request->topP(),
                    'topK' => $request->topK(),
                    'maxOutputTokens' => $request->maxTokens(),
                    'thinkingConfig' => $thinkingConfig,
                ]),
                'tools' => $tools !== [] ? $tools : null,
                'tool_config' => $toolConfig,
                'safetySettings' => $providerOptions['safetySettings'] ?? null,
                'service_tier' => $providerOptions['serviceTier'] ?? null,
            ])
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Gemini Error: [%s] %s',
                [
                    data_get($data, 'error.code', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }

        $finishReason = data_get($data, 'candidates.0.finishReason');
        $content = $this->extractTextContent($data);
        $thoughtTokens = data_get($data, 'usageMetadata.thoughtsTokenCount', 0);

        if ($finishReason === 'MAX_TOKENS') {
            $promptTokens = data_get($data, 'usageMetadata.promptTokenCount', 0);
            $candidatesTokens = data_get($data, 'usageMetadata.candidatesTokenCount', 0);
            $totalTokens = data_get($data, 'usageMetadata.totalTokenCount', 0);
            $outputTokens = $candidatesTokens - $thoughtTokens;

            $isEmpty = trim($content) === '';
            $isInvalidJson = $content !== '' && json_decode($content) === null;
            $contentLength = strlen($content);

            if (($isEmpty || $isInvalidJson) && $thoughtTokens > 0) {
                $errorDetail = $isEmpty
                    ? 'no tokens remained for structured output'
                    : "output was truncated at {$contentLength} characters resulting in invalid JSON";

                throw PrismException::providerResponseError(
                    'Gemini hit token limit with high thinking token usage. '.
                    "Token usage: {$promptTokens} prompt + {$thoughtTokens} thinking + {$outputTokens} output = {$totalTokens} total. ".
                    "The {$errorDetail}. ".
                    'Try increasing maxTokens to at least '.($totalTokens + 1000).' (suggested: '.($totalTokens * 2).' for comfortable margin).'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, FinishReason $finishReason): StructuredResponse
    {
        $this->addStep($data, $request, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): StructuredResponse
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'candidates.0.content.parts', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        if ($approvalRequests !== []) {
            // Record the tool calls + approval requests so the resume pass
            // can correlate approval responses.
            $request->addMessage(new AssistantMessage(
                $this->extractTextContent($data),
                $toolCalls,
                toolApprovalRequests: $approvalRequests,
            ));
        }

        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->addStep($data, $request, FinishReason::ToolCalls, $toolResults);

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, FinishReason $finishReason, array $toolResults = []): void
    {
        $isStructuredStep = $finishReason !== FinishReason::ToolCalls;
        $thoughtSummaries = $this->extractThoughtSummaries($data);
        $textContent = $this->extractTextContent($data);

        $this->responseBuilder->addStep(
            new Step(
                text: $textContent,
                finishReason: $finishReason,
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
                    'thoughtSummaries' => $thoughtSummaries !== [] ? $thoughtSummaries : null,
                    'citations' => CitationMapper::mapFromGemini(data_get($data, 'candidates.0', [])) ?: null,
                    'searchEntryPoint' => data_get($data, 'candidates.0.groundingMetadata.searchEntryPoint'),
                    'searchQueries' => data_get($data, 'candidates.0.groundingMetadata.webSearchQueries'),
                    'urlMetadata' => data_get($data, 'candidates.0.urlContextMetadata.urlMetadata'),
                ]),
                structured: $isStructuredStep ? $this->extractStructuredData(data_get($data, 'candidates.0.content.parts.0.text') ?? '') : [],
                toolCalls: $finishReason === FinishReason::ToolCalls ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
                toolResults: $toolResults,
                raw: $data,
            )
        );
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
