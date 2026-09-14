<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Usage;

it('constructs with required parameters', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
    );

    expect($usage->promptTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(50)
        ->and($usage->cacheWriteInputTokens)->toBeNull()
        ->and($usage->cacheReadInputTokens)->toBeNull()
        ->and($usage->thoughtTokens)->toBeNull()
        ->and($usage->cost)->toBeNull();
});

it('constructs with all parameters including cost', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5,
        cost: 0.00325,
    );

    expect($usage->promptTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(50)
        ->and($usage->cacheWriteInputTokens)->toBe(25)
        ->and($usage->cacheReadInputTokens)->toBe(10)
        ->and($usage->thoughtTokens)->toBe(5)
        ->and($usage->cost)->toBe(0.00325);
});

it('converts to array without cost', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
    );

    expect($usage->toArray())->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => null,
        'thought_tokens' => null,
        'cost' => null,
    ]);
});

it('converts to array with cost', function (): void {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5,
        cost: 0.0042,
    );

    expect($usage->toArray())->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cache_write_input_tokens' => 25,
        'cache_read_input_tokens' => 10,
        'thought_tokens' => 5,
        'cost' => 0.0042,
    ]);
});

it('allows zero cost', function (): void {
    $usage = new Usage(
        promptTokens: 0,
        completionTokens: 0,
        cost: 0.0,
    );

    expect($usage->cost)->toBe(0.0);
});

it('allows very small cost values', function (): void {
    $usage = new Usage(
        promptTokens: 10,
        completionTokens: 5,
        cost: 0.000001,
    );

    expect($usage->cost)->toBe(0.000001);
});
