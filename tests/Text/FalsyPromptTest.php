<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('keeps a prompt that is exactly "0"', function (): void {
    // "0" is a legitimate prompt — an answer to "how many?", a menu choice, a
    // test fixture. Gating on truthiness drops it.
    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('0')
        ->toRequest();

    expect($request->messages())->toHaveCount(1)
        ->and($request->messages()[0]->text())->toBe('0');
});

it('still refuses prompt and messages together when the prompt is "0"', function (): void {
    // The worse half. The refusal is gated on the same truthiness, so a caller
    // who set both gets no error AND no prompt — a successful call that
    // answered a different question than the one asked.
    expect(fn (): Request => (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages([new UserMessage('What is 2 + 2?')])
        ->withPrompt('0')
        ->toRequest())
        ->toThrow(PrismException::class);
});

it('keeps a prompt that is only whitespace', function (): void {
    // The regression guard for the fix itself. filled() would have been the
    // obvious way to write these guards, and it trims — so it turns "  " into
    // the same silent drop this test file exists to prevent, one input over.
    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('  ')
        ->toRequest();

    expect($request->messages())->toHaveCount(1)
        ->and($request->messages()[0]->text())->toBe('  ');
});

it('still refuses prompt and messages together when the prompt is whitespace', function (): void {
    expect(fn (): Request => (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages([new UserMessage('What is 2 + 2?')])
        ->withPrompt('  ')
        ->toRequest())
        ->toThrow(PrismException::class);
});

it('treats an empty prompt as no prompt, exactly as before', function (): void {
    // The other side of the boundary, pinned deliberately: "" and "0" are the
    // only falsy strings, and only "0" was meant to change behaviour here. An
    // empty prompt contributes no message and does not collide with messages.
    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages([new UserMessage('What is 2 + 2?')])
        ->withPrompt('')
        ->toRequest();

    expect($request->messages())->toHaveCount(1)
        ->and($request->messages()[0]->text())->toBe('What is 2 + 2?');
});
