<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Tests\Fixtures\FixtureResponse;

it('returns structured output using json_object mode', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Qwen, 'qwen-plus')
        ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    // Assert request format
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $messages = $data['input']['messages'];

        return str_contains($request->url(), 'text-generation/generation')
            && $data['model'] === 'qwen-plus'
            && $data['parameters']['result_format'] === 'message'
            && $data['parameters']['response_format'] === ['type' => 'json_object']
            // System prompt → user prompt → JSON schema instruction
            && $messages[0]['role'] === 'system'
            && $messages[1]['role'] === 'user'
            && $messages[2]['role'] === 'system'
            && str_contains((string) $messages[2]['content'], 'JSON');
    });

    // Assert response type
    expect($response)->toBeInstanceOf(StructuredResponse::class);

    // Assert structured data
    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString()->toBe('75º');
    expect($response->structured['game_time'])->toBeString()->toBe('3pm');
    expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

    // Assert metadata
    expect($response->meta->id)->toBe('c566fb8b-d0a3-92d0-b77a-ae1496d9f32f');
    expect($response->meta->model)->toBe('qwen-plus');
    expect($response->usage->promptTokens)->toBe(216);
    expect($response->usage->completionTokens)->toBe(27);
});

it('returns structured output using json_schema mode', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Qwen, 'qwen-plus')
        ->usingStructuredMode(StructuredMode::Structured)
        ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    // Assert request format
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $messages = $data['input']['messages'];
        $responseFormat = $data['parameters']['response_format'];

        return str_contains($request->url(), 'text-generation/generation')
            && $data['model'] === 'qwen-plus'
            && $data['parameters']['result_format'] === 'message'
            // json_schema mode sends response_format with json_schema type
            && $responseFormat['type'] === 'json_schema'
            && $responseFormat['json_schema']['name'] === 'output'
            && isset($responseFormat['json_schema']['schema'])
            && $responseFormat['json_schema']['strict'] === true
            // No JSON schema system message appended (only system prompt + user prompt)
            && count($messages) === 2
            && $messages[0]['role'] === 'system'
            && $messages[1]['role'] === 'user';
    });

    // Assert response type
    expect($response)->toBeInstanceOf(StructuredResponse::class);

    // Assert structured data
    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
});

it('uses json_object mode by default in auto mode', function (): void {
    FixtureResponse::fakeResponseSequence('text-generation/generation', 'qwen/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
        ],
        ['weather']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Qwen, 'qwen-plus')
        ->withPrompt('What is the weather?')
        ->asStructured();

    // Auto mode should default to json_object
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['parameters']['response_format'] === ['type' => 'json_object']
            // Should have the JSON schema system message appended
            && $data['input']['messages'][count($data['input']['messages']) - 1]['role'] === 'system';
    });

    expect($response)->toBeInstanceOf(StructuredResponse::class);
});
