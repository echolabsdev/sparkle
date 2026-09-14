<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

trait CallsTools
{
    /**
     * Execute tools and return results (for non-streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     */
    protected function callTools(array $tools, array $toolCalls): array
    {
        $toolResults = [];

        // Consume generator to execute all tools and collect results
        foreach ($this->callToolsAndYieldEvents($tools, $toolCalls, EventID::generate(), $toolResults) as $event) {
            // Events are discarded for non-streaming handlers
        }

        return $toolResults;
    }

    /**
     * Execute tools honoring client-executed and approval-required markers.
     *
     * Marked tool calls are NOT executed: $hasPendingToolCalls is set so the
     * handler stops its loop, and approval requests are collected into
     * $approvalRequests for correlation on resume.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolApprovalRequest[]  $approvalRequests
     * @return ToolResult[]
     */
    protected function callToolsWithPending(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequests): array
    {
        $toolResults = [];

        foreach ($this->callToolsAndYieldEventsWithPending($tools, $toolCalls, EventID::generate(), $toolResults, $hasPendingToolCalls) as $event) {
            if ($event instanceof ToolApprovalRequestEvent) {
                $approvalRequests[] = new ToolApprovalRequest(
                    approvalId: $event->approvalId,
                    toolCallId: $event->toolCall->id,
                );
            }
        }

        return $toolResults;
    }

    /**
     * Generate tool execution events and collect results (for streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults): Generator
    {
        $hasPendingToolCalls = false;

        yield from $this->yieldToolEvents($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls, filterPending: false);
    }

    /**
     * Streaming variant honoring client-executed and approval-required markers.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEventsWithPending(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls): Generator
    {
        yield from $this->yieldToolEvents($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls, filterPending: true);
    }

    /**
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function yieldToolEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls, bool $filterPending): Generator
    {
        $approvalRequiredToolCalls = [];

        $executableToolCalls = $filterPending
            ? $this->filterServerExecutedToolCalls($tools, $toolCalls, $hasPendingToolCalls, $approvalRequiredToolCalls)
            : $toolCalls;

        $groupedToolCalls = $this->groupToolCallsByConcurrency($tools, $executableToolCalls);

        $executionResults = $this->executeToolsWithConcurrency($tools, $groupedToolCalls, $messageId);

        // This tool batch belongs to the current step; advance the telemetry
        // step cursor so the next step's tools are tagged one step later.
        Telemetry::advanceStep(Telemetry::current());

        foreach (collect($executionResults)->keys()->sort() as $index) {
            $result = $executionResults[$index];

            $toolResults[] = $result['toolResult'];

            foreach ($result['events'] as $event) {
                yield $event;
            }
        }

        foreach ($approvalRequiredToolCalls as $toolCall) {
            yield new ToolApprovalRequestEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $messageId,
                approvalId: EventID::generate('apr'),
            );
        }
    }

    /**
     * Split out client-executed and approval-required tool calls, setting the
     * pending flag when any are found.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolCall[]  $approvalRequiredToolCalls  Collected by reference
     * @return array<int, ToolCall> Server-executed tool calls with original indices preserved
     */
    protected function filterServerExecutedToolCalls(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequiredToolCalls): array
    {
        $serverToolCalls = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isClientExecuted()) {
                    $hasPendingToolCalls = true;

                    continue;
                }

                if ($tool->needsApproval($toolCall->arguments())) {
                    $hasPendingToolCalls = true;
                    $approvalRequiredToolCalls[] = $toolCall;

                    continue;
                }

                $serverToolCalls[$index] = $toolCall;
            } catch (PrismException) {
                // Unknown tool — keep it so error handling fires in executeToolCall.
                $serverToolCalls[$index] = $toolCall;
            }
        }

        return $serverToolCalls;
    }

    /**
     * Yield stream completion events when marked tool calls are pending: the
     * step and stream end with FinishReason::ToolCalls so the consumer can
     * collect the pending calls and resume.
     *
     * @return Generator<StepFinishEvent|StreamEndEvent>
     */
    protected function yieldToolCallsFinishEvents(StreamState $state): Generator
    {
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $state->usage(),
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::ToolCalls,
            usage: $state->usage(),
            citations: $state->citations() !== [] ? $state->citations() : null,
        );
    }

    /**
     * Resolve pending tool approvals from a previous request (non-streaming).
     *
     * Scans the request messages for a ToolResultMessage carrying approval
     * responses after the last tool-calling AssistantMessage. Approved tools
     * are executed; denied or unanswered ones produce denial results
     * (deny-by-default). The tool message is replaced with one containing the
     * merged results so the conversation is complete before the next send.
     */
    protected function resolveToolApprovals(StructuredRequest|TextRequest $request): void
    {
        foreach ($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()) as $event) {
            // Events are discarded for non-streaming handlers
        }
    }

    /**
     * @return Generator<ToolResultEvent|ArtifactEvent>
     */
    protected function resolveToolApprovalsAndYieldEvents(StructuredRequest|TextRequest $request, string $messageId): Generator
    {
        $messages = $request->messages();

        $assistantMessage = null;
        $assistantMessageIndex = null;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistantMessage = $messages[$i];
                $assistantMessageIndex = $i;

                break;
            }
        }

        if (! $assistantMessage instanceof AssistantMessage || $assistantMessageIndex === null) {
            return;
        }

        $toolsByName = collect($request->tools())->keyBy(fn (Tool $tool): string => $tool->name());

        $isAnyToolApprovalConfigured = collect($assistantMessage->toolCalls)->contains(
            fn (ToolCall $toolCall): bool => $toolsByName->get($toolCall->name)?->hasApprovalConfigured() === true,
        );

        if (! $isAnyToolApprovalConfigured) {
            return;
        }

        // EVERY tool result message after the assistant, read as one. A stopped
        // step already carries a message with the results of the tools it did
        // run, and the decisions arrive in another one after it. Reading only
        // the first found the results and never the decisions, so an approved
        // call was denied by default.
        $toolMessageIndexes = [];
        $toolResultsSoFar = [];
        $approvalResponses = [];
        $messageCount = count($messages);

        for ($i = $assistantMessageIndex + 1; $i < $messageCount; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $toolMessageIndexes[] = $i;
                $toolResultsSoFar = [...$toolResultsSoFar, ...$messages[$i]->toolResults];
                $approvalResponses = [...$approvalResponses, ...$messages[$i]->toolApprovalResponses];
            }
        }

        $toolMessage = new ToolResultMessage($toolResultsSoFar, $approvalResponses);

        $toolCallIdToApprovalId = [];
        foreach ($assistantMessage->toolApprovalRequests as $approvalRequest) {
            $toolCallIdToApprovalId[$approvalRequest->toolCallId] = $approvalRequest->approvalId;
        }

        $approvalResolvedToolResults = [];

        foreach ($assistantMessage->toolCalls as $toolCall) {
            // A call that already has a result is DONE, approved or not. This
            // check used to run only for calls with no decision, so a call
            // approved on an earlier request ran again on every later request
            // whose history still carried the approval: a transfer approved
            // once executed once per turn after it.
            if (collect($toolMessage->toolResults)->contains(fn (ToolResult $toolResult): bool => $toolResult->toolCallId === $toolCall->id)) {
                continue;
            }

            $approvalId = $toolCallIdToApprovalId[$toolCall->id] ?? null;
            $approval = $approvalId !== null ? $toolMessage->findByApprovalId($approvalId) : null;

            if (! $approval instanceof ToolApprovalResponse) {
                if ($toolsByName->get($toolCall->name)?->hasApprovalConfigured() !== true) {
                    continue;
                }

                // Deny by default when no approval response was provided.
                $approval = new ToolApprovalResponse($approvalId ?? EventID::generate('apr'), false, 'No approval response provided');
            }

            if ($approval->approved) {
                $result = $this->executeToolCall($request->tools(), $toolCall, $messageId);

                $telemetryContext = Telemetry::current();

                if ($telemetryContext instanceof TelemetryContext) {
                    Telemetry::toolInvoked($telemetryContext, $toolCall, $result['toolResult'], $result['durationMs']);
                }

                $approvalResolvedToolResults[] = $result['toolResult'];

                foreach ($result['events'] as $event) {
                    yield $event;
                }

                continue;
            }

            $reason = $approval->reason ?? 'User denied tool execution';

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $reason,
                toolCallResultId: $toolCall->resultId,
            );

            $approvalResolvedToolResults[] = $toolResult;

            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $reason,
            );
        }

        if ($approvalResolvedToolResults === []) {
            return;
        }

        if ($toolMessageIndexes !== []) {
            $request->setMessages(array_values(array_filter(
                $messages,
                fn (int $index): bool => ! in_array($index, $toolMessageIndexes, true),
                ARRAY_FILTER_USE_KEY,
            )));
        }

        $request->addMessage(new ToolResultMessage(
            array_merge($toolMessage->toolResults, $approvalResolvedToolResults),
            $toolMessage->toolApprovalResponses,
        ));
    }

    /**
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}
     */
    protected function groupToolCallsByConcurrency(array $tools, array $toolCalls): array
    {
        $concurrent = [];
        $sequential = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isConcurrent()) {
                    $concurrent[$index] = $toolCall;
                } else {
                    $sequential[$index] = $toolCall;
                }
            } catch (PrismException) {
                $sequential[$index] = $toolCall;
            }
        }

        return [
            'concurrent' => $concurrent,
            'sequential' => $sequential,
        ];
    }

    /**
     * @param  Tool[]  $tools
     * @param  array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}  $groupedToolCalls
     * @return array<int, array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float}>
     */
    protected function executeToolsWithConcurrency(array $tools, array $groupedToolCalls, string $messageId): array
    {
        $results = [];

        $concurrentClosures = [];

        $telemetryContext = Telemetry::current();

        foreach ($groupedToolCalls['concurrent'] as $index => $toolCall) {
            $concurrentClosures[$index] = fn () => $this->executeToolCall($tools, $toolCall, $messageId);
        }

        if ($concurrentClosures !== []) {
            foreach (Concurrency::run($concurrentClosures) as $index => $result) {
                $results[$index] = $result;

                if ($telemetryContext instanceof TelemetryContext) {
                    $this->recordToolTelemetry($telemetryContext, $groupedToolCalls['concurrent'][$index], $result, $index);
                }
            }
        }

        foreach ($groupedToolCalls['sequential'] as $index => $toolCall) {
            $result = $this->executeToolCall($tools, $toolCall, $messageId);

            $results[$index] = $result;

            if ($telemetryContext instanceof TelemetryContext) {
                $this->recordToolTelemetry($telemetryContext, $toolCall, $result, $index);
            }
        }

        return $results;
    }

    /**
     * Emit a ToolInvoked telemetry event for a single executed tool call.
     *
     * Reads defensively because concurrently-executed results arrive back from
     * Concurrency::run() as loosely-typed values.
     */
    protected function recordToolTelemetry(TelemetryContext $context, ToolCall $toolCall, mixed $result, int $index): void
    {
        if (! is_array($result)) {
            return;
        }

        $toolResult = $result['toolResult'] ?? null;

        if (! $toolResult instanceof ToolResult) {
            return;
        }

        $durationMs = $result['durationMs'] ?? 0.0;

        Telemetry::toolInvoked($context, $toolCall, $toolResult, is_float($durationMs) ? $durationMs : 0.0, $index);
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float}
     */
    protected static function executeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        $events = [];

        $startedAt = microtime(true);

        try {
            $tool = self::resolveTool($toolCall->name, $tools);
            $output = call_user_func_array(
                $tool->handle(...),
                $toolCall->arguments()
            );

            if ($output instanceof ToolError) {
                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $output->message,
                    toolCallResultId: $toolCall->resultId,
                );

                $events[] = new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $messageId,
                    success: false,
                    error: $output->message,
                );

                return [
                    'toolResult' => $toolResult,
                    'events' => $events,
                    'durationMs' => (microtime(true) - $startedAt) * 1000,
                ];
            }

            if (is_string($output)) {
                $output = new ToolOutput(result: $output);
            }

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $output->result,
                toolCallResultId: $toolCall->resultId,
                artifacts: $output->artifacts,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: true
            );

            foreach ($toolResult->artifacts as $artifact) {
                $events[] = new ArtifactEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    artifact: $artifact,
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    messageId: $messageId,
                );
            }

            return [
                'toolResult' => $toolResult,
                'events' => $events,
                'durationMs' => (microtime(true) - $startedAt) * 1000,
            ];
        } catch (PrismException $e) {
            try {
                $args = $toolCall->arguments();
            } catch (PrismException) {
                // Malformed arguments are themselves the failure being reported —
                // surface the raw string so the model can see what it sent.
                $args = ['raw' => is_string($toolCall->arguments) ? $toolCall->arguments : ''];
            }

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $args,
                result: $e->getMessage(),
                toolCallResultId: $toolCall->resultId,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $e->getMessage()
            );

            return [
                'toolResult' => $toolResult,
                'events' => $events,
                'durationMs' => (microtime(true) - $startedAt) * 1000,
            ];
        }
    }

    /**
     * @param  Tool[]  $tools
     *
     * @throws PrismException
     */
    protected static function resolveTool(string $name, array $tools): Tool
    {
        try {
            return collect($tools)
                ->sole(fn (Tool $tool): bool => $tool->name() === $name);
        } catch (ItemNotFoundException $e) {
            throw PrismException::toolNotFound($name, $e);
        } catch (MultipleItemsFoundException $e) {
            throw PrismException::multipleToolsFound($name, $e);
        }
    }
}
