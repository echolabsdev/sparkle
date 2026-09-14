<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));

    $this->schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $this->weatherTool = (new Tool)
        ->as('weather')
        ->for('useful when you need to search for current weather conditions')
        ->withStringParameter('city', 'The city that you want the weather for')
        ->using(fn (string $city): string => 'The weather will be 75º and sunny');
});

describe('Structured output with tools for Mistral', function (): void {
    it('calls tools then re-sends without tools for the final structured response', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/structured-with-tool-call');

        $response = Prism::structured()
            ->using(Provider::Mistral, 'mistral-large-latest')
            ->withSchema($this->schema)
            ->withTools([$this->weatherTool])
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStructured();

        // Assert the tool call step
        expect($response->steps)->toHaveCount(2);

        $firstStep = $response->steps[0];
        expect($firstStep->finishReason)->toBe(FinishReason::ToolCalls);
        expect($firstStep->toolCalls)->toHaveCount(1);
        expect($firstStep->toolCalls[0]->name)->toBe('weather');
        expect($firstStep->toolCalls[0]->arguments())->toBe(['city' => 'Detroit']);
        expect($firstStep->toolResults)->toHaveCount(1);
        expect($firstStep->toolResults[0]->result)->toBe('The weather will be 75º and sunny');

        // Assert tool calls and results are aggregated onto the response
        expect($response->toolCalls)->toHaveCount(1);
        expect($response->toolResults)->toHaveCount(1);

        // Assert the final structured output
        expect($response->finishReason)->toBe(FinishReason::Stop);
        expect($response->structured)->toBeArray();
        expect($response->structured)->toHaveKeys([
            'weather',
            'game_time',
            'coat_required',
        ]);
        expect($response->structured['weather'])->toBeString()->toBe('75º and sunny');
        expect($response->structured['game_time'])->toBeString()->toBe('3pm');
        expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

        // Assert metadata and summed usage
        expect($response->meta->id)->toBe('9a2c3b41d6f04e8b8a1c0d9e7f5a3b21');
        expect($response->meta->model)->toBe('mistral-large-latest');
        expect($response->usage->promptTokens)->toBe(295);
        expect($response->usage->completionTokens)->toBe(60);

        // Mistral does not allow tools and response_format together: the first
        // request carries tools only, the final request carries json_schema only.
        Http::assertSentInOrder([
            function (Request $request): bool {
                $payload = $request->data();

                expect($payload['tools'])->toHaveCount(1);
                expect($payload['tools'][0]['function']['name'])->toBe('weather');
                expect($payload)->not->toHaveKey('response_format');

                return true;
            },
            function (Request $request): bool {
                $payload = $request->data();

                expect($payload)->not->toHaveKey('tools');
                expect($payload['response_format']['type'])->toBe('json_schema');
                expect($payload['response_format']['json_schema']['name'])->toBe('output');
                expect($payload['response_format']['json_schema']['strict'])->toBeTrue();

                // The tool call conversation is carried into the final request.
                $roles = array_column($payload['messages'], 'role');
                expect($roles)->toBe(['user', 'assistant', 'tool']);
                expect($payload['messages'][2]['content'])->toBe('The weather will be 75º and sunny');

                return true;
            },
        ]);
    });

    it('discards unconstrained output and re-sends without tools when the model stops without calling them', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/structured-with-unused-tools');

        $response = Prism::structured()
            ->using(Provider::Mistral, 'mistral-large-latest')
            ->withSchema($this->schema)
            ->withTools([$this->weatherTool])
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStructured();

        // The unconstrained first response is discarded: no tool step, only the
        // final structured step remains.
        expect($response->steps)->toHaveCount(1);
        expect($response->toolCalls)->toBeEmpty();
        expect($response->toolResults)->toBeEmpty();

        expect($response->finishReason)->toBe(FinishReason::Stop);
        expect($response->structured)->toBeArray();
        expect($response->structured)->toHaveKeys([
            'weather',
            'game_time',
            'coat_required',
        ]);
        expect($response->structured['weather'])->toBeString()->toBe('75º and sunny');
        expect($response->structured['game_time'])->toBeString()->toBe('3pm');
        expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

        expect($response->meta->id)->toBe('0f8c1d2e3a4b5c6d7e8f9a0b1c2d3e4f');
        expect($response->usage->promptTokens)->toBe(52);
        expect($response->usage->completionTokens)->toBe(36);

        Http::assertSentInOrder([
            function (Request $request): bool {
                $payload = $request->data();

                expect($payload['tools'])->toHaveCount(1);
                expect($payload)->not->toHaveKey('response_format');

                return true;
            },
            function (Request $request): bool {
                $payload = $request->data();

                expect($payload)->not->toHaveKey('tools');
                expect($payload['response_format']['type'])->toBe('json_schema');

                // The discarded assistant text is not carried into the re-send.
                $roles = array_column($payload['messages'], 'role');
                expect($roles)->toBe(['user']);

                return true;
            },
        ]);
    });
});
