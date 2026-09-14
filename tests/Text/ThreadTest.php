<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * A thread backed by a plain array — the shape most implementations take.
 */
final readonly class ArrayThread implements Thread
{
    /** @param  array<int, Message>  $messages */
    public function __construct(private array $messages) {}

    /** @return array<int, Message> */
    #[Override]
    public function messages(): array
    {
        return $this->messages;
    }
}

/**
 * A thread that yields, so history is never fully in memory. Also counts how
 * many times it was walked, which is how the memoisation is asserted.
 */
final class GeneratorThread implements Thread
{
    public int $walks = 0;

    /** @param  array<int, Message>  $messages */
    public function __construct(private readonly array $messages) {}

    /** @return Generator<int, Message> */
    #[Override]
    public function messages(): Generator
    {
        $this->walks++;

        yield from $this->messages;
    }
}

beforeEach(function (): void {
    $this->pendingRequest = new PendingRequest;
});

test('a thread supplies the conversation so far', function (): void {
    $thread = new ArrayThread([
        new UserMessage('What is the capital of France?'),
        new AssistantMessage('Paris.'),
    ]);

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->toRequest();

    expect($request->messages())->toHaveCount(2)
        ->and($request->messages()[0]->text())->toBe('What is the capital of France?')
        ->and($request->messages()[1]->content)->toBe('Paris.');
});

test('a thread composes with a prompt: history first, new turn last', function (): void {
    $thread = new ArrayThread([
        new UserMessage('What is the capital of France?'),
        new AssistantMessage('Paris.'),
    ]);

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->withPrompt('And its population?')
        ->toRequest();

    expect($request->messages())->toHaveCount(3)
        ->and($request->messages()[2])->toBeInstanceOf(UserMessage::class)
        ->and($request->messages()[2]->text())->toBe('And its population?');
});

test('a thread composes with explicit messages, history first', function (): void {
    $thread = new ArrayThread([new UserMessage('First.')]);

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->withMessages([new AssistantMessage('Second.')])
        ->toRequest();

    expect($request->messages())->toHaveCount(2)
        ->and($request->messages()[0]->text())->toBe('First.')
        ->and($request->messages()[1]->content)->toBe('Second.');
});

test('no thread leaves the message list untouched', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Hello')
        ->toRequest();

    expect($request->messages())->toHaveCount(1)
        ->and($request->messages()[0]->text())->toBe('Hello');
});

test('a thread can resume mid tool loop, carrying calls and results', function (): void {
    $toolCall = new ToolCall('call_1', 'weather', ['city' => 'Paris']);

    $thread = new ArrayThread([
        new UserMessage('What is the weather in Paris?'),
        new AssistantMessage('', [$toolCall]),
        new ToolResultMessage([
            new ToolResult('call_1', 'weather', ['city' => 'Paris'], 'Sunny, 24C'),
        ]),
    ]);

    $messages = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->toRequest()
        ->messages();

    expect($messages)->toHaveCount(3)
        ->and($messages[1]->toolCalls[0]->name)->toBe('weather')
        ->and($messages[1]->toolCalls[0]->id)->toBe('call_1')
        ->and($messages[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[2]->toolResults[0]->toolCallId)->toBe('call_1')
        ->and($messages[2]->toolResults[0]->result)->toBe('Sunny, 24C');
});

test('a generator backed thread survives being built twice', function (): void {
    $thread = new GeneratorThread([
        new UserMessage('First.'),
        new AssistantMessage('Second.'),
    ]);

    $pending = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread);

    expect($pending->toRequest()->messages())->toHaveCount(2)
        ->and($pending->toRequest()->messages())->toHaveCount(2)
        ->and($thread->walks)->toBe(1);
});

test('swapping the thread discards the previous history', function (): void {
    $pending = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread(new ArrayThread([new UserMessage('Old.')]));

    expect($pending->toRequest()->messages()[0]->text())->toBe('Old.');

    $pending->withThread(new ArrayThread([new UserMessage('New.')]));

    expect($pending->toRequest()->messages())->toHaveCount(1)
        ->and($pending->toRequest()->messages()[0]->text())->toBe('New.');
});

test('structured requests take a thread too', function (): void {
    $thread = new ArrayThread([
        new UserMessage('What is the capital of France?'),
        new AssistantMessage('Paris.'),
    ]);

    $request = (new StructuredPendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('population', 'the population'))
        ->withThread($thread)
        ->withPrompt('Give me its population as JSON.')
        ->toRequest();

    expect($request->messages())->toHaveCount(3)
        ->and($request->messages()[2]->text())->toBe('Give me its population as JSON.');
});

test('a collection backed thread works, as the docs recommend', function (): void {
    $thread = new class implements Thread
    {
        /** @return Collection<int, Message> */
        #[Override]
        public function messages(): Collection
        {
            return collect([
                new UserMessage('First.'),
                new AssistantMessage('Second.'),
            ]);
        }
    };

    $messages = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->toRequest()
        ->messages();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->text())->toBe('First.')
        ->and($messages[1]->content)->toBe('Second.');
});
