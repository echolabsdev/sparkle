<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum StreamEventType: string
{
    case StreamStart = 'stream_start';
    case TextStart = 'text_start';
    case TextDelta = 'text_delta';
    case TextComplete = 'text_complete';
    case ThinkingStart = 'thinking_start';
    case ThinkingDelta = 'thinking_delta';
    case ThinkingComplete = 'thinking_complete';
    case ToolCall = 'tool_call';
    case ToolCallDelta = 'tool_call_delta';
    case ProviderToolEvent = 'provider_tool_event';
    case ToolResult = 'tool_result';
    case ToolApprovalRequest = 'tool_approval_request';
    case Citation = 'citation';
    case Artifact = 'artifact';
    case Error = 'error';
    case StreamEnd = 'stream_end';
    case StepStart = 'step_start';
    case StepFinish = 'step_finish';
}
