<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\FixtureResponse;

class ToolApprovalTestHandler
{
    use CallsTools;

    public function execute(array $tools, array $toolCalls, bool &$hasPendingToolCalls = false): array
    {
        $approvalRequests = [];

        return $this->callToolsWithPending($tools, $toolCalls, $hasPendingToolCalls, $approvalRequests);
    }

    public function stream(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls = false): Generator
    {
        return $this->callToolsAndYieldEventsWithPending($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls);
    }

    public function resolve(Request $request): void
    {
        $this->resolveToolApprovals($request);
    }

    public function resolveStream(Request $request, string $messageId, ?StreamState $state = null): Generator
    {
        return $this->resolveToolApprovalsAndYieldEvents($request, $messageId);
    }
}

function getResolvedToolResults(Request $request): array
{
    foreach (array_reverse($request->messages()) as $message) {
        if ($message instanceof ToolResultMessage) {
            return $message->toolResults;
        }
    }

    return [];
}

function approvalTextRequest(array $messages = [], array $tools = []): Request
{
    return new Request(
        model: 'test-model',
        providerKey: 'test',
        systemPrompts: [],
        prompt: null,
        messages: $messages,
        maxSteps: 5,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: $tools,
        clientOptions: [],
        clientRetry: [0],
        toolChoice: ToolChoice::Auto,
    );
}

describe('Tool::requiresApproval()', function (): void {
    it('defaults to not requiring approval', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result');

        expect($tool->needsApproval())->toBeFalse();
    });

    it('can be marked as requiring approval with static true', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        expect($tool->needsApproval())->toBeTrue();
    });

    it('can be marked as requiring approval with explicit true', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval(true);

        expect($tool->needsApproval())->toBeTrue();
    });

    it('can be set to not require approval with false', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval(false);

        expect($tool->needsApproval())->toBeFalse();
    });

    it('supports dynamic approval via closure', function (): void {
        $tool = (new Tool)
            ->as('transfer')
            ->for('Transfer money')
            ->withNumberParameter('amount', 'Amount')
            ->using(fn (float $amount): string => "Transferred {$amount}")
            ->requiresApproval(fn (array $args): bool => $args['amount'] > 1000);

        expect($tool->needsApproval(['amount' => 500]))->toBeFalse();
        expect($tool->needsApproval(['amount' => 1500]))->toBeTrue();
    });

    it('hasApprovalConfigured returns true for static or closure without invoking closure', function (): void {
        $staticTool = (new Tool)->as('a')->for('A')->using(fn (): string => '')->requiresApproval();
        expect($staticTool->hasApprovalConfigured())->toBeTrue();

        $closureTool = (new Tool)->as('b')->for('B')->using(fn (): string => '')
            ->requiresApproval(fn (array $args): bool => $args['x'] > 0);
        expect($closureTool->hasApprovalConfigured())->toBeTrue();

        $disabledTool = (new Tool)->as('c')->for('C')->using(fn (): string => '')->requiresApproval(false);
        expect($disabledTool->hasApprovalConfigured())->toBeFalse();
    });
});

describe('Phase 1: filterServerExecutedToolCalls with approval tools', function (): void {
    it('skips approval-required tools and sets pending flag', function (): void {
        $normalTool = (new Tool)
            ->as('normal_tool')
            ->for('A normal tool')
            ->using(fn (): string => 'result');

        $approvalTool = (new Tool)
            ->as('approval_tool')
            ->for('Needs approval')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'normal_tool', arguments: []),
            new ToolCall(id: 'call-2', name: 'approval_tool', arguments: []),
        ];

        $handler = new ToolApprovalTestHandler;
        $hasPendingToolCalls = false;
        $results = $handler->execute([$normalTool, $approvalTool], $toolCalls, $hasPendingToolCalls);

        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('normal_tool')
            ->and($hasPendingToolCalls)->toBeTrue();
    });

    it('emits ToolApprovalRequestEvent in streaming for approval-required tools', function (): void {
        $approvalTool = (new Tool)
            ->as('dangerous_tool')
            ->for('Dangerous operation')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'dangerous_tool', arguments: ['action' => 'delete']),
        ];

        $handler = new ToolApprovalTestHandler;
        $toolResults = [];
        $hasPendingToolCalls = false;
        $events = [];

        foreach ($handler->stream([$approvalTool], $toolCalls, 'msg-123', $toolResults, $hasPendingToolCalls) as $event) {
            $events[] = $event;
        }

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(ToolApprovalRequestEvent::class)
            ->and($events[0]->toolCall->id)->toBe('call-1')
            ->and($events[0]->toolCall->name)->toBe('dangerous_tool')
            ->and($events[0]->messageId)->toBe('msg-123')
            ->and($hasPendingToolCalls)->toBeTrue()
            ->and($toolResults)->toBeEmpty();
    });

    it('yields ToolApprovalRequestEvent after server-executed tool results in streaming', function (): void {
        $normalTool = (new Tool)
            ->as('normal')
            ->for('Normal tool')
            ->using(fn (): string => 'normal result');

        $approvalTool = (new Tool)
            ->as('approval')
            ->for('Approval tool')
            ->using(fn (): string => 'should not run')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'normal', arguments: []),
            new ToolCall(id: 'call-2', name: 'approval', arguments: []),
        ];

        $handler = new ToolApprovalTestHandler;
        $toolResults = [];
        $hasPendingToolCalls = false;
        $events = [];

        foreach ($handler->stream([$normalTool, $approvalTool], $toolCalls, 'msg-123', $toolResults, $hasPendingToolCalls) as $event) {
            $events[] = $event;
        }

        expect($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->toolCallId)->toBe('call-1')
            ->and($events[0]->toolResult->result)->toBe('normal result')
            ->and($events[1])->toBeInstanceOf(ToolApprovalRequestEvent::class)
            ->and($events[1]->toolCall->id)->toBe('call-2')
            ->and($hasPendingToolCalls)->toBeTrue()
            ->and($toolResults)->toHaveCount(1);
    });

    it('handles mixed tools: normal, client-executed, and approval-required', function (): void {
        $normalTool = (new Tool)
            ->as('normal')
            ->for('Normal tool')
            ->using(fn (): string => 'normal result');

        $clientTool = (new Tool)
            ->as('client')
            ->for('Client tool')
            ->clientExecuted();

        $approvalTool = (new Tool)
            ->as('approval')
            ->for('Approval tool')
            ->using(fn (): string => 'should not run')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'normal', arguments: []),
            new ToolCall(id: 'call-2', name: 'client', arguments: []),
            new ToolCall(id: 'call-3', name: 'approval', arguments: []),
        ];

        $handler = new ToolApprovalTestHandler;
        $hasPendingToolCalls = false;
        $results = $handler->execute([$normalTool, $clientTool, $approvalTool], $toolCalls, $hasPendingToolCalls);

        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('normal')
            ->and($hasPendingToolCalls)->toBeTrue();
    });

    it('skips tool with dynamic approval only when closure returns true', function (): void {
        $tool = (new Tool)
            ->as('transfer')
            ->for('Transfer money')
            ->withNumberParameter('amount', 'Amount')
            ->using(fn (float $amount): string => "Transferred {$amount}")
            ->requiresApproval(fn (array $args): bool => $args['amount'] > 1000);

        $smallTransfer = new ToolCall(id: 'call-1', name: 'transfer', arguments: ['amount' => 500]);
        $largeTransfer = new ToolCall(id: 'call-2', name: 'transfer', arguments: ['amount' => 2000]);

        $handler = new ToolApprovalTestHandler;

        $hasPending = false;
        $results = $handler->execute([$tool], [$smallTransfer], $hasPending);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('Transferred 500')
            ->and($hasPending)->toBeFalse();

        $hasPending = false;
        $results = $handler->execute([$tool], [$largeTransfer], $hasPending);
        expect($results)->toBeEmpty()
            ->and($hasPending)->toBeTrue();
    });
});

describe('Phase 2: resolveToolApprovals', function (): void {
    it('returns empty when no ToolResultMessage with toolApprovalResponses exists', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [new UserMessage('hello')],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        expect(getResolvedToolResults($request))->toBeEmpty();
    });

    it('executes approved tools', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('delete_file')
            ->and($results[0]->result)->toBe('Deleted: /tmp/test.txt')
            ->and($results[0]->toolCallId)->toBe('call-1');

        $messages = $request->messages();
        $lastMessage = end($messages);
        expect($lastMessage)->toBeInstanceOf(ToolResultMessage::class);
    });

    it('creates denial result for denied tools', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: false, reason: 'Too dangerous'),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('delete_file')
            ->and($results[0]->result)->toBe('Too dangerous')
            ->and($results[0]->toolCallId)->toBe('call-1');
    });

    it('adds denial when no approval response provided for approval-required tool', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], []),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('No approval response provided')
            ->and($results[0]->toolCallId)->toBe('call-1');
    });

    it('replaces ToolResultMessage with toolApprovalResponses with merged ToolResultMessage', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('test'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'test_tool', arguments: []),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $toolResultMessages = collect($request->messages())->filter(fn ($m): bool => $m instanceof ToolResultMessage);
        expect($toolResultMessages)->toHaveCount(1);

        $toolResultMessage = $toolResultMessages->first();
        expect($toolResultMessage->toolResults)->toHaveCount(1)
            ->and($toolResultMessage->toolResults[0]->result)->toBe('result')
            ->and($toolResultMessage->toolApprovalResponses)->toHaveCount(1)
            ->and($toolResultMessage->toolApprovalResponses[0]->approvalId)->toBe('approval-1')
            ->and($toolResultMessage->toolApprovalResponses[0]->approved)->toBeTrue();
    });

    it('yields ToolResultEvent for each resolved tool in streaming', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/b.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                    new ToolApprovalResponse(approvalId: 'approval-2', approved: false, reason: 'Keep this file'),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $events = [];

        foreach ($handler->resolveStream($request, 'msg-456') as $event) {
            $events[] = $event;
        }

        expect($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->toolCallId)->toBe('call-1')
            ->and($events[0]->toolResult->result)->toBe('Deleted: /tmp/a.txt')
            ->and($events[0]->success)->toBeTrue()
            ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[1]->toolResult->toolCallId)->toBe('call-2')
            ->and($events[1]->toolResult->result)->toBe('Keep this file')
            ->and($events[1]->success)->toBeFalse();
    });

    it('yields ToolResultEvents for approved tools before request is updated for LLM continuation', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete files'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/b.txt']),
                        new ToolCall(id: 'call-3', name: 'delete_file', arguments: ['path' => '/tmp/c.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                        new ToolApprovalRequest(approvalId: 'approval-3', toolCallId: 'call-3'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                    new ToolApprovalResponse(approvalId: 'approval-2', approved: true),
                    new ToolApprovalResponse(approvalId: 'approval-3', approved: false, reason: 'Skip this'),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $events = iterator_to_array($handler->resolveStream($request, 'msg-789'));

        // All ToolResultEvents must be yielded before generator completes (i.e. before LLM would continue)
        expect($events)->toHaveCount(3)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->toolCallId)->toBe('call-1')
            ->and($events[0]->toolResult->result)->toBe('Deleted: /tmp/a.txt')
            ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[1]->toolResult->toolCallId)->toBe('call-2')
            ->and($events[1]->toolResult->result)->toBe('Deleted: /tmp/b.txt')
            ->and($events[2])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[2]->toolResult->toolCallId)->toBe('call-3')
            ->and($events[2]->toolResult->result)->toBe('Skip this')
            ->and($events[2]->success)->toBeFalse();

        // Request is only updated with merged ToolResultMessage after all events are yielded
        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(3)
            ->and($results[0]->result)->toBe('Deleted: /tmp/a.txt')
            ->and($results[1]->result)->toBe('Deleted: /tmp/b.txt')
            ->and($results[2]->result)->toBe('Skip this');
    });

    it('yields ToolResultEvents for approved tools in order before generator completes', function (): void {
        $approvalTool = (new Tool)
            ->as('transfer')
            ->for('Transfer money')
            ->withNumberParameter('amount', 'Amount')
            ->using(fn (float $amount): string => "Transferred {$amount}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('transfer money'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'transfer', arguments: ['amount' => 100]),
                        new ToolCall(id: 'call-2', name: 'transfer', arguments: ['amount' => 500]),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                    new ToolApprovalResponse(approvalId: 'approval-2', approved: true),
                ]),
            ],
            tools: [$approvalTool],
        );

        $handler = new ToolApprovalTestHandler;
        $events = iterator_to_array($handler->resolveStream($request, 'msg-stream'));

        // All ToolResultEvents must appear before the generator is exhausted (simulating "before LLM continues")
        expect($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->result)->toBe('Transferred 100')
            ->and($events[0]->success)->toBeTrue()
            ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[1]->toolResult->result)->toBe('Transferred 500')
            ->and($events[1]->success)->toBeTrue();
    });

    it('merges resolved tool results with existing client-executed results', function (): void {
        $approvalTool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('search and delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'search', arguments: []),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                    ],
                ),
                new ToolResultMessage(
                    toolResults: [
                        new ToolResult(toolCallId: 'call-1', toolName: 'search', args: [], result: 'client search result'),
                    ],
                    toolApprovalResponses: [
                        new ToolApprovalResponse(approvalId: 'approval-2', approved: true),
                    ],
                ),
            ],
            tools: [
                (new Tool)->as('search')->for('Search')->using(fn (): string => '')->clientExecuted(),
                $approvalTool,
            ],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $toolResultMessage = collect($request->messages())->first(fn ($m): bool => $m instanceof ToolResultMessage);
        expect($toolResultMessage->toolResults)->toHaveCount(2)
            ->and($toolResultMessage->toolResults[0]->toolCallId)->toBe('call-1')
            ->and($toolResultMessage->toolResults[0]->result)->toBe('client search result')
            ->and($toolResultMessage->toolResults[1]->toolCallId)->toBe('call-2')
            ->and($toolResultMessage->toolResults[1]->result)->toBe('Deleted: /tmp/test.txt');
    });

    it('skips approval-required tools that already have a result in the tool message', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/b.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                    ],
                ),
                new ToolResultMessage(
                    toolResults: [
                        new ToolResult(toolCallId: 'call-1', toolName: 'delete_file', args: ['path' => '/tmp/a.txt'], result: 'Already executed'),
                    ],
                    toolApprovalResponses: [
                        new ToolApprovalResponse(approvalId: 'approval-2', approved: false, reason: 'User denied'),
                    ],
                ),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(2)
            ->and($results[0]->toolCallId)->toBe('call-1')
            ->and($results[0]->result)->toBe('Already executed')
            ->and($results[1]->toolCallId)->toBe('call-2')
            ->and($results[1]->result)->toBe('User denied');
    });

    it('only resolves tool calls with approval responses, skipping others', function (): void {
        $normalTool = (new Tool)
            ->as('search')
            ->for('Search the web')
            ->using(fn (): string => 'search results');

        $approvalTool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('search and delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'search', arguments: []),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-2', toolCallId: 'call-2'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-2', approved: true),
                ]),
            ],
            tools: [$normalTool, $approvalTool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('delete_file')
            ->and($results[0]->result)->toBe('Deleted: /tmp/test.txt');
    });

    it('adds denial for last assistant when no approval message follows it', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $messages = [
            new UserMessage('first request'),
            new AssistantMessage(
                content: '',
                toolCalls: [
                    new ToolCall(id: 'call-old', name: 'test_tool', arguments: []),
                ],
                additionalContent: [],
                toolApprovalRequests: [
                    new ToolApprovalRequest(approvalId: 'approval-old', toolCallId: 'call-old'),
                ],
            ),
            new ToolResultMessage([], [
                new ToolApprovalResponse(approvalId: 'approval-old', approved: true),
            ]),
            new ToolResultMessage([
                new ToolResult(toolCallId: 'call-old', toolName: 'test_tool', args: [], result: 'done'),
            ]),
            new UserMessage('second request'),
            new AssistantMessage(
                content: '',
                toolCalls: [
                    new ToolCall(id: 'call-new', name: 'test_tool', arguments: []),
                ],
                additionalContent: [],
                toolApprovalRequests: [
                    new ToolApprovalRequest(approvalId: 'approval-new', toolCallId: 'call-new'),
                ],
            ),
        ];

        $request = approvalTextRequest(
            messages: $messages,
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolCallId)->toBe('call-new')
            ->and($results[0]->result)->toBe('No approval response provided');
    });

    it('resolves approval message that comes after the last assistant message', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('first request'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-old', name: 'test_tool', arguments: []),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-old', toolCallId: 'call-old'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-old', approved: true),
                ]),
                new ToolResultMessage([
                    new ToolResult(toolCallId: 'call-old', toolName: 'test_tool', args: [], result: 'done'),
                ]),
                new UserMessage('second request'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-new', name: 'test_tool', arguments: []),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-new', toolCallId: 'call-new'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-new', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolCallId)->toBe('call-new')
            ->and($results[0]->result)->toBe('result');
    });

    it('handles denial without explicit reason using default message', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('test'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'test_tool', arguments: []),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: false),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('User denied tool execution');
    });

    it('does not yield StreamStartEvent when stream has already started', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $state = new StreamState;
        $state->markStreamStarted();

        $handler = new ToolApprovalTestHandler;
        $events = iterator_to_array($handler->resolveStream($request, 'msg-nostart', $state));

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->toolCallId)->toBe('call-1');
    });

    it('does not yield StreamStartEvent when no tool results are produced', function (): void {
        $tool = (new Tool)
            ->as('search')
            ->for('Search')
            ->withStringParameter('query', 'Query')
            ->using(fn (string $query): string => "Results for: {$query}");

        $request = approvalTextRequest(
            messages: [
                new UserMessage('search'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'search', arguments: ['query' => 'test']),
                    ],
                ),
                new ToolResultMessage([
                    new ToolResult(toolCallId: 'call-1', toolName: 'search', args: ['query' => 'test'], result: 'Results'),
                ]),
            ],
            tools: [$tool],
        );

        $state = new StreamState;
        $handler = new ToolApprovalTestHandler;
        $events = iterator_to_array($handler->resolveStream($request, 'msg-none', $state));

        expect($events)->toHaveCount(0);
        expect($state->hasStreamStarted())->toBeFalse();
    });

    it('does not yield StreamStartEvent when no StreamState is provided', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $events = iterator_to_array($handler->resolveStream($request, 'msg-nostate'));

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->toolCallId)->toBe('call-1');
    });

    it('returns empty when message history has no AssistantMessage', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('hello'),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        expect(getResolvedToolResults($request))->toBeEmpty();
    });

    it('returns empty when ToolResultMessage appears before AssistantMessage', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = approvalTextRequest(
            messages: [
                new UserMessage('hello'),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'approval-1', approved: true),
                ]),
                new AssistantMessage(
                    content: 'I will do it.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'test_tool', arguments: []),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
                    ],
                ),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('No approval response provided');
    });
});

describe('Phase 1: concurrent tools with approval', function (): void {
    it('executes concurrent tools and skips approval-required tools', function (): void {
        $concurrentTool = (new Tool)
            ->as('fast_lookup')
            ->for('Fast lookup')
            ->withStringParameter('query', 'Query')
            ->using(fn (string $query): string => "Found: {$query}")
            ->concurrent();

        $approvalTool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $anotherConcurrentTool = (new Tool)
            ->as('quick_check')
            ->for('Quick check')
            ->withStringParameter('item', 'Item')
            ->using(fn (string $item): string => "Checked: {$item}")
            ->concurrent();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'fast_lookup', arguments: ['query' => 'test']),
            new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/file.txt']),
            new ToolCall(id: 'call-3', name: 'quick_check', arguments: ['item' => 'status']),
        ];

        $handler = new ToolApprovalTestHandler;
        $hasPendingToolCalls = false;
        $results = $handler->execute(
            [$concurrentTool, $approvalTool, $anotherConcurrentTool],
            $toolCalls,
            $hasPendingToolCalls,
        );

        expect($results)->toHaveCount(2)
            ->and($results[0]->toolName)->toBe('fast_lookup')
            ->and($results[0]->result)->toBe('Found: test')
            ->and($results[1]->toolName)->toBe('quick_check')
            ->and($results[1]->result)->toBe('Checked: status')
            ->and($hasPendingToolCalls)->toBeTrue();
    });

    it('executes concurrent tools and skips approval-required tools in streaming', function (): void {
        $concurrentTool = (new Tool)
            ->as('fast_lookup')
            ->for('Fast lookup')
            ->withStringParameter('query', 'Query')
            ->using(fn (string $query): string => "Found: {$query}")
            ->concurrent();

        $approvalTool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'fast_lookup', arguments: ['query' => 'test']),
            new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/file.txt']),
        ];

        $handler = new ToolApprovalTestHandler;
        $toolResults = [];
        $hasPendingToolCalls = false;
        $events = [];

        foreach ($handler->stream([$concurrentTool, $approvalTool], $toolCalls, 'msg-123', $toolResults, $hasPendingToolCalls) as $event) {
            $events[] = $event;
        }

        $toolResultEvents = array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent);
        $approvalEvents = array_filter($events, fn ($e): bool => $e instanceof ToolApprovalRequestEvent);

        expect($toolResults)->toHaveCount(1)
            ->and($toolResults[0]->toolName)->toBe('fast_lookup')
            ->and(array_values($toolResultEvents))->toHaveCount(1)
            ->and(array_values($approvalEvents))->toHaveCount(1)
            ->and($hasPendingToolCalls)->toBeTrue();
    });
});

describe('ToolApprovalRequest value object', function (): void {
    it('serializes to array correctly', function (): void {
        $request = new ToolApprovalRequest(
            approvalId: 'approval-1',
            toolCallId: 'call-1',
        );

        expect($request->toArray())->toBe([
            'approval_id' => 'approval-1',
            'tool_call_id' => 'call-1',
        ]);
    });
});

describe('ToolApprovalResponse value object', function (): void {
    it('serializes to array correctly', function (): void {
        $response = new ToolApprovalResponse(
            approvalId: 'call-123',
            approved: true,
            reason: 'User confirmed',
        );

        expect($response->toArray())->toBe([
            'approval_id' => 'call-123',
            'approved' => true,
            'reason' => 'User confirmed',
        ]);
    });
});

describe('ToolResultMessage with toolApprovalResponses', function (): void {
    it('finds approval responses by approval ID', function (): void {
        $message = new ToolResultMessage([], [
            new ToolApprovalResponse(approvalId: 'call-1', approved: true),
            new ToolApprovalResponse(approvalId: 'call-2', approved: false),
        ]);

        $found = $message->findByApprovalId('call-1');
        expect($found)->not->toBeNull()
            ->and($found->approved)->toBeTrue();

        $found2 = $message->findByApprovalId('call-2');
        expect($found2)->not->toBeNull()
            ->and($found2->approved)->toBeFalse();

        expect($message->findByApprovalId('nonexistent'))->toBeNull();
    });

    it('serializes tool approval responses in toArray', function (): void {
        $message = new ToolResultMessage([], [
            new ToolApprovalResponse(approvalId: 'call-1', approved: true),
        ]);

        $array = $message->toArray();
        expect($array['type'])->toBe('tool_result')
            ->and($array['tool_approval_responses'])->toHaveCount(1)
            ->and($array['tool_approval_responses'][0]['approval_id'])->toBe('call-1')
            ->and($array['tool_approval_responses'][0]['approved'])->toBeTrue();
    });

    it('serializes both tool results and approval responses in toArray', function (): void {
        $message = new ToolResultMessage(
            toolResults: [
                new ToolResult(toolCallId: 'call-1', toolName: 'delete', args: [], result: 'done'),
            ],
            toolApprovalResponses: [
                new ToolApprovalResponse(approvalId: 'call-1', approved: true),
            ],
        );

        $array = $message->toArray();
        expect($array['tool_results'])->toHaveCount(1)
            ->and($array['tool_approval_responses'])->toHaveCount(1)
            ->and($array['tool_approval_responses'][0]['approval_id'])->toBe('call-1')
            ->and($array['tool_approval_responses'][0]['approved'])->toBeTrue();
    });
});

describe('AssistantMessage with toolApprovalRequests', function (): void {
    it('serializes tool approval requests in toArray', function (): void {
        $message = new AssistantMessage(
            content: '',
            toolCalls: [
                new ToolCall(id: 'call-1', name: 'delete', arguments: []),
            ],
            additionalContent: [],
            toolApprovalRequests: [
                new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call-1'),
            ],
        );

        $array = $message->toArray();
        expect($array['tool_approval_requests'])->toHaveCount(1)
            ->and($array['tool_approval_requests'][0])->toBe([
                'approval_id' => 'approval-1',
                'tool_call_id' => 'call-1',
            ]);
    });
});

describe('ToolApprovalRequestEvent', function (): void {
    it('has correct event type', function (): void {
        $event = new ToolApprovalRequestEvent(
            id: 'evt-1',
            timestamp: 1234567890,
            toolCall: new ToolCall(id: 'call-1', name: 'test_tool', arguments: ['key' => 'value']),
            messageId: 'msg-1',
            approvalId: 'approval-1',
        );

        expect($event->type())->toBe(StreamEventType::ToolApprovalRequest);

        $array = $event->toArray();
        expect($array['approval_id'])->toBe('approval-1')
            ->and($array['tool_name'])->toBe('test_tool')
            ->and($array['tool_id'])->toBe('call-1')
            ->and($array['arguments'])->toEqual((object) ['key' => 'value'])
            ->and($array['message_id'])->toBe('msg-1');
    });
});

describe('the documented resume, through a real provider handler', function (): void {
    // Every other test here builds the resumed messages by hand, with the
    // approval requests already on the assistant message. The documented flow
    // resumes from $response->messages, and that list left out the approval
    // requests and the results of the tools the stopped step did run. An
    // approved call was denied by default ("No approval response provided"),
    // and the other call went back with no output. Driven through the real
    // handlers over faked HTTP, because the seam was between them.

    function approvalTools(array &$ran): array
    {
        return [
            \Prism\Prism\Facades\Tool::as('weather')->for('Weather')->withStringParameter('city', 'City')
                ->using(function (string $city) use (&$ran): string {
                    $ran['weather']++;

                    return "The weather in {$city} will be 75° and sunny";
                })
                ->requiresApproval(),
            \Prism\Prism\Facades\Tool::as('search')->for('Search')->withStringParameter('query', 'Query')
                ->using(function (string $query) use (&$ran): string {
                    $ran['search']++;

                    return 'The tigers game is today at 3pm in detroit';
                }),
        ];
    }

    it('runs an approved call once, and sends every call its output (OpenAI)', function (bool $approved, string $weatherOutput): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-multiple-tools');
        $ran = ['weather' => 0, 'search' => 0];

        $response = Prism::text()->using('openai', 'gpt-4o')->withTools(approvalTools($ran))->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')->asText();

        $request = $response->steps->last()->toolApprovalRequests[0];

        expect($response->messages->last())->toBeInstanceOf(ToolResultMessage::class)
            ->and($response->messages[1]->toolApprovalRequests)->toEqual([$request]);

        Prism::text()->using('openai', 'gpt-4o')->withTools(approvalTools($ran))->withMaxSteps(3)
            ->withMessages([...$response->messages, new ToolResultMessage([], [
                new ToolApprovalResponse($request->approvalId, $approved, $approved ? null : 'Not today'),
            ])])
            ->asText();

        $outputs = collect(json_decode((string) Http::recorded()[1][0]->body(), true)['input'])
            ->where('type', 'function_call_output')
            ->pluck('output', 'call_id')
            ->all();

        expect($ran)->toBe(['weather' => $approved ? 1 : 0, 'search' => 1])
            ->and($outputs)->toBe([
                'call_AZkZynIOpPQwJ4fyZETY4eTP' => 'The tigers game is today at 3pm in detroit',
                'call_huHZrdh8RFUy8a8Uqbb2qV1x' => $weatherOutput,
            ]);
    })->with([
        'approved' => [true, 'The weather in Detroit will be 75° and sunny'],
        'denied' => [false, 'Not today'],
    ]);

    it('runs an approved call from a later step (Anthropic)', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-multiple-tools');
        $ran = ['weather' => 0, 'search' => 0];

        $response = Prism::text()->using('anthropic', 'claude-sonnet-4-6')->withTools(approvalTools($ran))->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')->asText();

        $request = $response->steps->last()->toolApprovalRequests[0];

        Prism::text()->using('anthropic', 'claude-sonnet-4-6')->withTools(approvalTools($ran))->withMaxSteps(3)
            ->withMessages([...$response->messages, new ToolResultMessage([], [new ToolApprovalResponse($request->approvalId, true)])])
            ->asText();

        $messages = json_decode((string) Http::recorded()[2][0]->body(), true)['messages'];
        $toolResults = collect($messages)->flatMap(fn (array $m): array => is_array($m['content']) ? $m['content'] : [])
            ->where('type', 'tool_result')->pluck('content', 'tool_use_id')->all();

        expect($ran)->toBe(['weather' => 1, 'search' => 1])
            ->and($toolResults)->toBe([
                'toolu_011Arf1KyG1ViN7sySoSjTPa' => 'The tigers game is today at 3pm in detroit',
                'toolu_01RXXxkijxvGSWPG4Aa8ZTE3' => 'The weather in Detroit will be 75° and sunny',
            ]);
    });

    it('does not run an approved call again on the turn after it ran', function (): void {
        // The approval stays in the conversation, so every later request
        // passes it back through resolveToolApprovals. The call already has its
        // result there, and must not run a second time.
        $fixture = fn (int $n): string => (string) file_get_contents(__DIR__."/Fixtures/openai/generate-text-with-multiple-tools-{$n}.json");
        Http::fakeSequence()->push($fixture(1))->push($fixture(2))->push($fixture(2));
        $ran = ['weather' => 0, 'search' => 0];
        // Not an arrow function: it would capture $ran by value, and the tools
        // would count into a copy.
        $pending = function () use (&$ran) {
            return Prism::text()->using('openai', 'gpt-4o')->withTools(approvalTools($ran))->withMaxSteps(3);
        };

        $response = $pending()->withPrompt('What time is the tigers game today and should I wear a coat?')->asText();
        $request = $response->steps->last()->toolApprovalRequests[0];

        $resumed = $pending()
            ->withMessages([...$response->messages, new ToolResultMessage([], [new ToolApprovalResponse($request->approvalId, true)])])
            ->asText();

        $pending()->withMessages([...$resumed->messages, new UserMessage('Thanks')])->asText();

        $lastInput = collect(json_decode((string) Http::recorded()[2][0]->body(), true)['input']);

        expect($ran)->toBe(['weather' => 1, 'search' => 1])
            ->and($lastInput->where('type', 'function_call_output'))->toHaveCount(2)
            // Results stay where they were, before the new turn, not after it.
            ->and($lastInput->last()['role'] ?? null)->toBe('user');
    });

    it('keeps the results of a run that stopped at its step budget', function (): void {
        // The same omission without any approval: withMaxSteps(1) runs the
        // tools and stops, and a conversation continued from $response->messages
        // replayed the calls with no results.
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-multiple-tools');
        $ran = ['weather' => 0, 'search' => 0];
        $tools = array_map(fn ($tool) => $tool->requiresApproval(false), approvalTools($ran));

        $response = Prism::text()->using('openai', 'gpt-4o')->withTools($tools)->withMaxSteps(1)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')->asText();

        expect($response->messages->last())->toBeInstanceOf(ToolResultMessage::class)
            ->and(collect($response->messages->last()->toolResults)->pluck('toolName')->sort()->values()->all())->toBe(['search', 'weather']);
    });
});
