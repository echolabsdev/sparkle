<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\ContextStack;
use Prism\Prism\Telemetry\TelemetryContext;

function stackContext(string $traceId): TelemetryContext
{
    return new TelemetryContext($traceId, TelemetryOperation::Text, 'openai', 'gpt-x', microtime(true));
}

it('returns null when empty', function (): void {
    $stack = new ContextStack;

    expect($stack->current())->toBeNull();
    expect($stack->pop())->toBeNull();
});

it('reads the top context and pops LIFO', function (): void {
    $stack = new ContextStack;
    $a = stackContext('a');
    $b = stackContext('b');

    $stack->push($a);
    $stack->push($b);

    expect($stack->current())->toBe($b);
    expect($stack->pop())->toBe($b);
    expect($stack->current())->toBe($a);
    expect($stack->pop())->toBe($a);
    expect($stack->current())->toBeNull();
});

it('removes a specific context out of order without corrupting the stack', function (): void {
    $stack = new ContextStack;
    $a = stackContext('a');
    $b = stackContext('b');

    $stack->push($a);
    $stack->push($b);

    // Simulate an abandoned inner generator popping the outer context.
    expect($stack->pop($a))->toBe($a);
    expect($stack->current())->toBe($b);
});

it('returns null when popping a context that is not present', function (): void {
    $stack = new ContextStack;
    $stack->push(stackContext('a'));

    expect($stack->pop(stackContext('missing')))->toBeNull();
    expect($stack->current()?->traceId)->toBe('a');
});

it('clears the stack', function (): void {
    $stack = new ContextStack;
    $stack->push(stackContext('a'));

    $stack->clear();

    expect($stack->current())->toBeNull();
});

it('tracks an independent step cursor per generation', function (): void {
    $stack = new ContextStack;
    $stack->push(stackContext('a'));
    $stack->push(stackContext('b'));

    // both start at 0
    expect($stack->stepFor('a'))->toBe(0);
    expect($stack->stepFor('b'))->toBe(0);

    $stack->advanceStepFor('a');
    $stack->advanceStepFor('a');

    expect($stack->stepFor('a'))->toBe(2);
    expect($stack->stepFor('b'))->toBe(0); // sibling generation unaffected
});

it('defaults the step cursor to 0 for an unknown generation', function (): void {
    expect((new ContextStack)->stepFor('never-started'))->toBe(0);
});

it('clears the step cursor for a generation', function (): void {
    $stack = new ContextStack;
    $stack->push(stackContext('a'));
    $stack->advanceStepFor('a');

    $stack->clearStepFor('a');

    expect($stack->stepFor('a'))->toBe(0);
});
