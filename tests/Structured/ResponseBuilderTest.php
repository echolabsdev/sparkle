<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

test('throws a PrismStructuredDecodingException if the response is not valid json', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: 'This is not valid json',
        finishReason: FinishReason::Stop,
        usage: new Usage(
            promptTokens: 0,
            completionTokens: 0
        ),
        meta: new Meta(
            id: '123',
            model: 'Test',
        ),
        messages: [],
        systemPrompts: [],
    ));

    $builder->toResponse();
})->throws(PrismStructuredDecodingException::class);

test('StructuredResponseBuilder aggregates usage and decodes structured output', function (): void {
    $builder = new ResponseBuilder;

    // First (intermediate) step
    $builder->addStep(new Step(
        text: 'intermediate output',
        finishReason: FinishReason::Length,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    // Final step that should be decoded
    $builder->addStep(new Step(
        text: '{"value":42}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 3, completionTokens: 2),
        meta: new Meta('step2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->promptTokens)->toBe(13)
        ->and($response->usage->completionTokens)->toBe(7)
        ->and($response->structured)->toBe(['value' => 42])
        ->and($response->text)->toBe('{"value":42}')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->steps)->toHaveCount(2);
});

test('StructuredResponseBuilder decode the json given as a markdown fenced code block', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: '```json
		{
		"value": 42
		}
		```',
        finishReason: FinishReason::Stop,
        usage: new Usage(
            promptTokens: 0,
            completionTokens: 0
        ),
        meta: new Meta(
            id: '123',
            model: 'Test',
        ),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->structured)->toBe(['value' => 42]);
});

test('StructuredResponseBuilder aggregates tool calls from multiple steps as ToolCall objects', function (): void {
    $builder = new ResponseBuilder;

    $toolCall1 = new ToolCall(
        id: 'call_1',
        name: 'get_weather',
        arguments: '{"location":"NYC"}'
    );

    $toolCall2 = new ToolCall(
        id: 'call_2',
        name: 'get_temperature',
        arguments: ['location' => 'LA']
    );

    $toolCall3 = new ToolCall(
        id: 'call_3',
        name: 'get_humidity',
        arguments: '{"location":"SF"}'
    );

    $builder->addStep(new Step(
        text: '',
        finishReason: FinishReason::ToolCalls,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
        toolCalls: [$toolCall1, $toolCall2],
    ));

    $builder->addStep(new Step(
        text: '{"result":"success"}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 5, completionTokens: 3),
        meta: new Meta('step2', 'test-model'),
        messages: [],
        systemPrompts: [],
        toolCalls: [$toolCall3],
    ));

    $response = $builder->toResponse();

    expect($response->toolCalls)->toHaveCount(3)
        ->and($response->toolCalls[0])->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls[0]->id)->toBe('call_1')
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[1])->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls[1]->id)->toBe('call_2')
        ->and($response->toolCalls[1]->name)->toBe('get_temperature')
        ->and($response->toolCalls[2])->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls[2]->id)->toBe('call_3')
        ->and($response->toolCalls[2]->name)->toBe('get_humidity');
});

test('StructuredResponseBuilder aggregates tool results from multiple steps as ToolResult objects', function (): void {
    $builder = new ResponseBuilder;

    $toolResult1 = new ToolResult(
        toolCallId: 'call_1',
        toolName: 'get_weather',
        args: ['location' => 'NYC'],
        result: ['temperature' => 75, 'condition' => 'sunny']
    );

    $toolResult2 = new ToolResult(
        toolCallId: 'call_2',
        toolName: 'get_temperature',
        args: ['location' => 'LA'],
        result: 80
    );

    $toolResult3 = new ToolResult(
        toolCallId: 'call_3',
        toolName: 'get_humidity',
        args: ['location' => 'SF'],
        result: 'High'
    );

    $builder->addStep(new Step(
        text: '',
        finishReason: FinishReason::ToolCalls,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
        toolResults: [$toolResult1, $toolResult2],
    ));

    $builder->addStep(new Step(
        text: '{"result":"success"}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 5, completionTokens: 3),
        meta: new Meta('step2', 'test-model'),
        messages: [],
        systemPrompts: [],
        toolResults: [$toolResult3],
    ));

    $response = $builder->toResponse();

    expect($response->toolResults)->toHaveCount(3)
        ->and($response->toolResults[0])->toBeInstanceOf(ToolResult::class)
        ->and($response->toolResults[0]->toolCallId)->toBe('call_1')
        ->and($response->toolResults[0]->toolName)->toBe('get_weather')
        ->and($response->toolResults[0]->result)->toBe(['temperature' => 75, 'condition' => 'sunny'])
        ->and($response->toolResults[1])->toBeInstanceOf(ToolResult::class)
        ->and($response->toolResults[1]->toolCallId)->toBe('call_2')
        ->and($response->toolResults[1]->toolName)->toBe('get_temperature')
        ->and($response->toolResults[1]->result)->toBe(80)
        ->and($response->toolResults[2])->toBeInstanceOf(ToolResult::class)
        ->and($response->toolResults[2]->toolCallId)->toBe('call_3')
        ->and($response->toolResults[2]->toolName)->toBe('get_humidity')
        ->and($response->toolResults[2]->result)->toBe('High');
});

test('StructuredResponseBuilder aggregates cost across steps', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: 'intermediate',
        finishReason: FinishReason::Length,
        usage: new Usage(promptTokens: 10, completionTokens: 5, cost: 0.0015),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $builder->addStep(new Step(
        text: '{"value":42}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 3, completionTokens: 2, cost: 0.0008),
        meta: new Meta('step2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->cost)->toBe(0.0023);
});

test('StructuredResponseBuilder keeps cost null when no steps have cost', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: '{"value":42}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->cost)->toBeNull();
});

test('StructuredResponseBuilder returns empty arrays when no tool calls or results exist', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: '{"value":42}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->toolCalls)->toBeArray()
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->toolResults)->toBeArray()
        ->and($response->toolResults)->toBeEmpty();
});
