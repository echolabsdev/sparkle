<?php

declare(strict_types=1);

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Text\Request;

function createTextRequestWithToolChoice(string|ToolChoice|null $toolChoice): Request
{
    return new Request(
        model: 'gpt-4',
        providerKey: 'openai',
        systemPrompts: [],
        prompt: 'Hello',
        messages: [],
        maxSteps: 5,
        maxTokens: null,
        temperature: null,
        topP: null,
        topK: null,
        tools: [],
        clientOptions: [],
        clientRetry: [3, 100],
        toolChoice: $toolChoice,
    );
}

describe('resetToolChoice', function (): void {
    it('resets string tool choice to auto', function (): void {
        $request = createTextRequestWithToolChoice('weather');

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('resets ToolChoice::Any to auto', function (): void {
        $request = createTextRequestWithToolChoice(ToolChoice::Any);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('does not reset ToolChoice::Auto', function (): void {
        $request = createTextRequestWithToolChoice(ToolChoice::Auto);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::Auto);
    });

    it('does not reset ToolChoice::None', function (): void {
        $request = createTextRequestWithToolChoice(ToolChoice::None);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBe(ToolChoice::None);
    });

    it('does not reset null', function (): void {
        $request = createTextRequestWithToolChoice(null);

        $request->resetToolChoice();

        expect($request->toolChoice())->toBeNull();
    });

    it('returns self for method chaining', function (): void {
        $request = createTextRequestWithToolChoice('weather');

        $result = $request->resetToolChoice();

        expect($result)->toBe($request);
    });
});
