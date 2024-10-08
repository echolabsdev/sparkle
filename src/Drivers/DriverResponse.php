<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Drivers;

use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\ValueObjects\ToolCall;
use EchoLabs\Prism\ValueObjects\Usage;

class DriverResponse
{
    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array{id: string, model: string}  $response
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly Usage $usage,
        public readonly FinishReason $finishReason,
        public readonly array $response,
    ) {}
}
