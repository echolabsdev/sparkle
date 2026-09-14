<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Provider as ProviderContract;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestDoubles\TestProvider;

enum TestModel: string
{
    case Gpt4 = 'gpt-4';
    case Gpt35 = 'gpt-3.5-turbo';
}

beforeEach(function (): void {
    $this->pendingRequest = new PendingRequest;
    $this->provider = new TestProvider;
});

test('it can configure the provider and model', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4');

    $generated = $request->toRequest();

    expect($generated->model())->toBe('gpt-4');
});

test('it can configure the provider and model with custom config via using', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4', ['url' => 'value']);

    expect($request->provider()->url)->toBe('value');
});

test('it can configure the provider and model with custom config via withProviderConfig', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->usingProviderConfig(['url' => 'value']);

    expect($request->provider()->url)->toBe('value');
});

test('it sets provider options', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions())
        ->toBe(['key' => 'value']);
});

test('it sets provider options on request object', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions())
        ->toBe(['key' => 'value']);
});

test('it gets provider options on a pending request', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['key' => 'value']);

    expect($request->providerOptions())->toBe(['key' => 'value']);
});

test('it gets specific provider option with path', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['key' => 'value']);

    expect($request->providerOptions('key'))->toBe('value');
});

test('it gets specific provider option from request object', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions('key'))->toBe('value');
});

test('it gets nested provider option from request object', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withProviderOptions(['nested' => ['key' => 'value']]);

    $generated = $request->toRequest();

    expect($generated->providerOptions('nested.key'))->toBe('value');
});

test('it allows you to get the model and provider', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4');

    expect($request->model())->toBe('gpt-4');
    expect($request->providerKey())->toBe('openai');
});

test('it configures the client options', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withClientOptions(['timeout' => 30]);

    $generated = $request->toRequest();

    expect($generated->clientOptions())
        ->toBe(['timeout' => 30]);
});

test('it configures client retry', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withClientRetry(3, 100);

    $generated = $request->toRequest();

    expect($generated->clientRetry())
        ->toBe([3, 100, null, true]);
});

test('it sets max tokens', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMaxTokens(100);

    $generated = $request->toRequest();

    expect($generated->maxTokens())->toBe(100);
});

test('it sets temperature', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->usingTemperature(0.7);

    $generated = $request->toRequest();

    expect($generated->temperature())->toBe(0.7);
});

test('it sets top p', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->usingTopP(0.9);

    $generated = $request->toRequest();

    expect($generated->topP())->toBe(0.9);
});

test('it sets top k', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->usingTopK(40);

    $generated = $request->toRequest();

    expect($generated->topK())->toBe(40);
});

test('it sets max steps', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMaxSteps(5);

    $generated = $request->toRequest();

    expect($generated->maxSteps())->toBe(5);
});

test('it can add a tool', function (): void {
    $tool = Tool::as('test')
        ->for('test tool')
        ->using(fn (): string => 'test result');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withTools([$tool]);

    $generated = $request->toRequest();

    expect($generated->tools())
        ->toHaveCount(1)
        ->and($generated->tools()[0]->name())
        ->toBe('test');
});

test('it sets tool choice', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withToolChoice(ToolChoice::Auto);

    $generated = $request->toRequest();

    expect($generated->toolChoice())
        ->toBe(ToolChoice::Auto);
});

test('it can set string prompt', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Hello AI');

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('Hello AI')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class);
});

test('it can set view prompt', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('Hello AI');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt($view);

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('Hello AI')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class);
});

test('it filters livewire morph markers from view prompts', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('<!--[if BLOCK]><![endif]-->Hello AI<!--[if ENDBLOCK]><![endif]-->');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt($view);

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('Hello AI')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class);
});

test('it preserves legitimate html comments in view prompts', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('<!-- This is a comment -->Hello AI');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt($view);

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('<!-- This is a comment -->Hello AI')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class);
});

test('it does not filter string prompts', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('<!--[if BLOCK]><![endif]-->Hello AI<!--[if ENDBLOCK]><![endif]-->');

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('<!--[if BLOCK]><![endif]-->Hello AI<!--[if ENDBLOCK]><![endif]-->')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class);
});

test('it can set string system prompt', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt('System instruction');

    $generated = $request->toRequest();

    expect($generated->systemPrompts()[0]->content)
        ->toBe('System instruction');
});

test('it can set view system prompt', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('System instruction');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt($view);

    $generated = $request->toRequest();

    expect($generated->systemPrompts()[0]->content)
        ->toBe('System instruction');
});

test('it filters livewire morph markers from view system prompts', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('<!--[if BLOCK]><![endif]-->System instruction<!--[if ENDBLOCK]><![endif]-->');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt($view);

    $generated = $request->toRequest();

    expect($generated->systemPrompts()[0]->content)
        ->toBe('System instruction');
});

test('it preserves legitimate html comments in view system prompts', function (): void {
    $view = Mockery::mock(View::class);
    $view->shouldReceive('render')->andReturn('<!-- Important -->System instruction');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt($view);

    $generated = $request->toRequest();

    expect($generated->systemPrompts()[0]->content)
        ->toBe('<!-- Important -->System instruction');
});

test('it does not filter string system prompts', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt('<!--[if BLOCK]><![endif]-->System instruction<!--[if ENDBLOCK]><![endif]-->');

    $generated = $request->toRequest();

    expect($generated->systemPrompts()[0]->content)
        ->toBe('<!--[if BLOCK]><![endif]-->System instruction<!--[if ENDBLOCK]><![endif]-->');
});

test('it can set messages', function (): void {
    $messages = [
        new SystemMessage('system'),
        new UserMessage('user'),
        new AssistantMessage('assistant'),
    ];

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages($messages);

    $generated = $request->toRequest();

    expect($generated->messages())
        ->toHaveCount(3)
        ->sequence(
            fn ($message) => $message->toBeInstanceOf(SystemMessage::class),
            fn ($message) => $message->toBeInstanceOf(UserMessage::class),
            fn ($message) => $message->toBeInstanceOf(AssistantMessage::class),
        );
});

test('it can set system prompts', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompts([
            new SystemMessage('Prompt 1'),
            new SystemMessage('Prompt 2'),
        ]);

    $generated = $request->toRequest();

    expect($generated->systemPrompts())
        ->toHaveCount(2)
        ->sequence(
            fn ($message): bool => $message->content === 'Prompt 1',
            fn ($message): bool => $message->content === 'Prompt 2',
        );
});

test('it throws exception when using both prompt and messages', function (): void {
    $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('test')
        ->withMessages([new UserMessage('test')])
        ->toRequest();
})->throws(PrismException::class, 'You can only use `prompt` or `messages`');

test('it throws exception when using both messages and prompt', function (): void {
    $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages([new UserMessage('test')])
        ->withPrompt('test')
        ->toRequest();
})->throws(PrismException::class, 'You can only use `prompt` or `messages`');

test('it generates response', function (): void {
    resolve('prism-manager')->extend('test-provider', fn ($config): ProviderContract => new TestProvider);

    $response = $this->pendingRequest
        ->using('test-provider', 'test-model')
        ->withPrompt('test')
        ->asText();

    expect($response->text)->toBe("I'm nyx!");
});

test('you can run toRequest multiple times', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Hello AI');

    $request->toRequest();
    $request->toRequest();
})->throwsNoExceptions();

test('it can set prompt with additional content', function (): void {
    $image = Image::fromUrl('https://example.com/image.jpg');

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Analyze this image', [$image]);

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('Analyze this image')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class)
        ->and($generated->messages()[0]->additionalContent)->toHaveCount(2) // Text + Image
        ->and($generated->messages()[0]->images())->toHaveCount(1)
        ->and($generated->messages()[0]->images()[0])->toBe($image);
});

test('withPrompt maintains backward compatibility without additional content', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Hello AI');

    $generated = $request->toRequest();

    expect($generated->prompt())->toBe('Hello AI')
        ->and($generated->messages()[0])->toBeInstanceOf(UserMessage::class)
        ->and($generated->messages()[0]->additionalContent)->toHaveCount(1); // Only Text
});

test('it can configure the model using a backed enum', function (): void {
    $modelEnum = TestModel::Gpt4;

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, $modelEnum);

    $generated = $request->toRequest();

    expect($generated->model())->toBe('gpt-4');
});
