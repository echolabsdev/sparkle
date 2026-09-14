<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('TextResponseBuilder aggregates usage and forwards final step text', function (): void {
    $builder = new ResponseBuilder;

    // Intermediate step
    $builder->addStep(new Step(
        text: 'hello ',
        finishReason: FinishReason::Length,
        toolCalls: [],
        toolResults: [],
        providerToolCalls: [],
        usage: new Usage(promptTokens: 5, completionTokens: 0),
        meta: new Meta('s1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    // Final step
    $builder->addStep(new Step(
        text: 'world',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        providerToolCalls: [],
        usage: new Usage(promptTokens: 2, completionTokens: 3),
        meta: new Meta('s2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->promptTokens)->toBe(7)
        ->and($response->usage->completionTokens)->toBe(3)
        ->and($response->text)->toBe('world')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->steps)->toHaveCount(2);
});

test('TextResponseBuilder aggregates cost across steps', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: 'hello ',
        finishReason: FinishReason::Length,
        toolCalls: [],
        toolResults: [],
        providerToolCalls: [],
        usage: new Usage(promptTokens: 5, completionTokens: 0, cost: 0.001),
        meta: new Meta('s1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $builder->addStep(new Step(
        text: 'world',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        providerToolCalls: [],
        usage: new Usage(promptTokens: 2, completionTokens: 3, cost: 0.002),
        meta: new Meta('s2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->cost)->toBe(0.003);
});

test('TextResponseBuilder keeps cost null when no steps have cost', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: 'hello',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        providerToolCalls: [],
        usage: new Usage(promptTokens: 5, completionTokens: 3),
        meta: new Meta('s1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->cost)->toBeNull();
});
