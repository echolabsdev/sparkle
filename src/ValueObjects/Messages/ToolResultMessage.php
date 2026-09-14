<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolResultMessage implements Arrayable, Message
{
    use HasProviderOptions;

    /**
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalResponse[]  $toolApprovalResponses  Approval decisions supplied by the application for pending approval requests
     */
    public function __construct(
        public readonly array $toolResults = [],
        public readonly array $toolApprovalResponses = []
    ) {}

    public function findByApprovalId(string $approvalId): ?ToolApprovalResponse
    {
        foreach ($this->toolApprovalResponses as $response) {
            if ($response->approvalId === $approvalId) {
                return $response;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'tool_result',
            'tool_results' => array_map(fn (ToolResult $toolResult): array => $toolResult->toArray(), $this->toolResults),
            'tool_approval_responses' => array_map(fn (ToolApprovalResponse $response): array => $response->toArray(), $this->toolApprovalResponses),
        ];
    }
}
