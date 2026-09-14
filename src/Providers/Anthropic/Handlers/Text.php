<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsCitations;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsProviderToolCalls;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsText;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsThinking;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesHttpRequests;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolMap;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools, ExtractsCitations, ExtractsProviderToolCalls, ExtractsText, ExtractsThinking, HandlesHttpRequests, ProcessesRateLimits;

    protected Response $tempResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client, protected TextRequest $request)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(): Response
    {
        $this->resolveToolApprovals($this->request);

        $this->sendRequest();

        $this->prepareTempResponse();

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls(),
            FinishReason::Pause => $this->handlePause(),
            // Refusal and unknown reasons resolve gracefully (prism-php/prism#996);
            // the response carries the mapped finish reason for the caller.
            default => $this->handleStop(),
        };
    }

    /**
     * @param  TextRequest  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        return Arr::whereNotNull([
            'model' => $request->model(),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()) ?: null,
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'thinking' => static::resolveThinking($request),
            'max_tokens' => $request->maxTokens() ?? 64000,
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => static::buildTools($request) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'mcp_servers' => $request->providerOptions('mcp_servers'),
            'cache_control' => $request->providerOptions('cache_control'),
            // A RAW PASSTHROUGH, deliberately not a typed builder.
            //
            // The edits are DATED identifiers -- `clear_tool_uses_20250919`,
            // `clear_thinking_20251015`, `compact_20260112` -- and the beta
            // header is dated too (`context-management-2025-06-27`). A typed
            // surface would freeze a shape that is going to move, then need
            // deprecating; a passthrough carries all three edits and whatever
            // replaces them, for one line.
            //
            // Reported as #35 with the gap measured rather than described: the
            // beta HEADER was already reachable through
            // providerOptions('anthropic_beta'), and this body is an allowlist,
            // so the request silently never carried the field. A caller could
            // switch the beta on and have nothing happen, with nothing anywhere
            // reporting a problem.
            'context_management' => $request->providerOptions('context_management'),
            'output_config' => $request->providerOptions('effort') !== null
                ? ['effort' => $request->providerOptions('effort')]
                : null,
        ]);
    }

    /**
     * Anthropic returns stop_reason="pause_turn" when a long-running server-side
     * tool (e.g. web_search, web_fetch) needs the client to continue the turn.
     * Per Anthropic's docs, the client should append the assistant message to
     * the conversation and re-send the request unchanged so the model can resume.
     */
    protected function handlePause(): Response
    {
        $this->addStep();

        $this->request->addMessage(new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
        ));

        if ($this->responseBuilder->steps->count() < $this->request->maxSteps()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleToolCalls(): Response
    {
        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($this->request->tools(), $this->tempResponse->toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($toolResults, $approvalRequests);

        $this->request->addMessage(new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
            $approvalRequests,
        ));

        $toolResultMessage = new ToolResultMessage($toolResults);

        $this->request->addMessage($toolResultMessage);
        $this->request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->responseBuilder->steps->count() < $this->request->maxSteps()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(): Response
    {
        $this->addStep();

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    protected function addStep(array $toolResults = [], array $toolApprovalRequests = []): void
    {
        $data = $this->httpResponse->json();

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
            providerToolCalls: $this->extractProviderToolCalls($data),
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            raw: $data,
            toolApprovalRequests: $toolApprovalRequests,
        ));
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new Response(
            steps: new Collection,
            text: $this->extractText($data),
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            toolCalls: $this->extractToolCalls($data),
            toolResults: [],
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens'),
                thoughtTokens: data_get($data, 'usage.output_tokens_details.thinking_tokens')
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($this->httpResponse)
            ),
            messages: new Collection,
            additionalContent: Arr::whereNotNull([
                'citations' => $this->extractCitations($data),
                // What the server actually cleared. Without it a caller cannot
                // tell "cleared 40 tool results" from "the beta header was
                // ignored" -- both look identical from outside: a successful
                // response and a smaller bill nobody can attribute.
                //
                // That is the half of #35 carrying the weight. Sending the
                // request without surfacing the answer is a feature you cannot
                // verify, which on a context-management edit means trusting the
                // single highest-leverage thing in a token budget on faith.
                'context_management' => data_get($data, 'context_management'),
                ...$this->extractThinking($data),
                ...$this->extractProviderToolContent($data),
            ])
        );
    }

    /**
     * Extract server tool use and result content blocks from the response.
     *
     * These must be preserved in additionalContent so that MessageMap can
     * replay them alongside citations during multi-step tool loops.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function extractProviderToolContent(array $data): array
    {
        $providerToolCalls = [];
        $providerToolResults = [];

        foreach (data_get($data, 'content', []) as $content) {
            $type = data_get($content, 'type');

            if ($type === 'server_tool_use') {
                $providerToolCalls[] = [
                    'type' => $type,
                    'id' => data_get($content, 'id'),
                    'name' => data_get($content, 'name'),
                    'input' => json_encode(data_get($content, 'input', [])),
                ];
            }

            if (str_ends_with((string) $type, '_tool_result')) {
                $providerToolResults[] = [
                    'type' => $type,
                    'tool_use_id' => data_get($content, 'tool_use_id'),
                    'content' => data_get($content, 'content'),
                ];
            }
        }

        return Arr::whereNotNull([
            'provider_tool_calls' => $providerToolCalls !== [] ? $providerToolCalls : null,
            'provider_tool_results' => $providerToolResults !== [] ? $providerToolResults : null,
        ]);
    }

    /**
     * @return array<int|string,mixed>
     */
    protected static function buildTools(TextRequest $request): array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                'name' => $tool->name,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }

    /**
     * @param  TextRequest  $request
     * @return array<string, mixed>|null
     */
    protected static function resolveThinking(PrismRequest $request): ?array
    {
        if ($request->reasoningEnabled() === false) {
            return null;
        }

        if ($request->providerOptions('thinking.type') === 'adaptive') {
            return ['type' => 'adaptive'];
        }

        if ($request->providerOptions('thinking.enabled') === true) {
            return [
                'type' => 'enabled',
                'budget_tokens' => is_int($request->providerOptions('thinking.budgetTokens'))
                    ? $request->providerOptions('thinking.budgetTokens')
                    : config('prism.providers.anthropic.default_thinking_budget', 1024),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = [];
        $contents = data_get($data, 'content', []);

        foreach ($contents as $content) {
            if (data_get($content, 'type') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: data_get($content, 'id'),
                    name: data_get($content, 'name'),
                    arguments: data_get($content, 'input')
                );
            }
        }

        return $toolCalls;
    }
}
