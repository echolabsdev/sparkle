<?php

declare(strict_types=1);

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request;

function createStructuredRequestWithToolChoice(string|ToolChoice|null $toolChoice): Request
{
    return new Request(
        systemPrompts: [],
        model: 'gpt-4',
        providerKey: 'openai',
        prompt: 'Hello',
        messages: [],
        maxTokens: null,
        temperature: null,
        topP: null,
        topK: null,
        clientOptions: [],
        clientRetry: [3, 100],
        schema: new ObjectSchema(
            name: 'user',
            description: 'A user object',
            properties: [
                new StringSchema('name', 'The user name'),
            ],
            requiredFields: ['name'],
        ),
        mode: StructuredMode::Auto,
        tools: [],
        toolChoice: $toolChoice,
        maxSteps: 5,
    );
}

describe('resetToolChoice', function (): void {
    it('resets string tool choice to auto', function (): void {
        $request = createStructuredRequestWithToolChoice('weather');

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('resets ToolChoice::Any to auto', function (): void {
        $request = createStructuredRequestWithToolChoice(ToolChoice::Any);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('does not reset ToolChoice::Auto', function (): void {
        $request = createStructuredRequestWithToolChoice(ToolChoice::Auto);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('does not reset ToolChoice::None', function (): void {
        $request = createStructuredRequestWithToolChoice(ToolChoice::None);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::None);
    });

    it('does not reset null', function (): void {
        $request = createStructuredRequestWithToolChoice(null);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBeNull();
    });

    it('returns self for method chaining', function (): void {
        $request = createStructuredRequestWithToolChoice('weather');

        $result = $request->resetToolChoice();

        expect($result)->toBe($request);
    });
});
