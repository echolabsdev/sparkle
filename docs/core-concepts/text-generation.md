# Text Generation

Prism provides a powerful interface for generating text using Large Language Models (LLMs). This guide covers everything from basic usage to advanced features like multi-modal interactions and response handling.

## Basic Text Generation

At its simplest, you can generate text with just a few lines of code:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Tell me a short story about a brave knight.')
    ->asText();

echo $response->text;
```

## System Prompts and Context

System prompts help set the behavior and context for the AI. They're particularly useful for maintaining consistent responses or giving the LLM a persona:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->asText();
```

You can also use Laravel views for complex system prompts:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(view('prompts.math-tutor'))
    ->withPrompt('What is calculus?')
    ->asText();
```

You an also pass a View to the `withPrompt` method.

## Multi-Modal Input

Prism supports including images, documents, audio, and video files in your prompts for rich multi-modal analysis:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Video;

// Analyze an image
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'What objects do you see in this image?',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();

// Process a document
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'Summarize the key points from this document',
        [Document::fromLocalPath('/path/to/document.pdf')]
    )
    ->asText();

// Analyze audio content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'What is being discussed in this audio?',
        [Audio::fromLocalPath('/path/to/audio.mp3')]
    )
    ->asText();

// Process video content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Describe what happens in this video',
        [Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();

// Multiple media types in one prompt
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Compare this image with the information in this document',
        [
            Image::fromLocalPath('/path/to/chart.png'),
            Document::fromLocalPath('/path/to/report.pdf')
        ]
    )
    ->asText();

// Multi-image analysis with Qwen VL models
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-vl-max')
    ->withPrompt(
        'What are these?',
        [
            Image::fromUrl('https://example.com/dog.jpeg'),
            Image::fromUrl('https://example.com/tiger.png'),
            Image::fromUrl('https://example.com/rabbit.png'),
        ]
    )
    ->asText();
```

## Message Chains and Conversations

For interactive conversations, use message chains to maintain context:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->asText();
```

### Message Types

- `SystemMessage`
- `UserMessage`
- `AssistantMessage`
- `ToolResultMessage`

> [!NOTE]
> Some providers, like Anthropic, do not support the `SystemMessage` type. In those cases we convert `SystemMessage` to `UserMessage`.

### Threads

Rebuilding the whole message array on every request gets old fast. If your conversation already lives somewhere — a database table, a cache entry, a session — implement `Thread` and hand Prism the conversation instead:

```php
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread;

class Conversation extends Model implements Thread
{
    /** @return iterable<int, Message> */
    public function messages(): iterable
    {
        return $this->turns->map(fn (Turn $turn): Message => $turn->toPrismMessage());
    }
}
```

Then pass it in. A thread is the history, and `withPrompt()` is the turn you're taking now — so unlike `withMessages()`, the two work together:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withThread($conversation)
    ->withPrompt('Can you show me an example?')
    ->asText();
```

Prism only ever reads from a thread, so saving a turn stays yours to do. Everything you need is on the response: `$response->messages` is the full exchange, tool calls and tool results included, which means a conversation interrupted mid-tool-loop can be stored and resumed exactly where it stopped.

```php
$conversation->record($response->messages);
```

`messages()` returns an `iterable`, so a generator can page a long history out of storage instead of hydrating every row up front. Prism still materialises the final list — the provider payload needs the whole conversation — so this lowers the cost of reading history, not the peak memory of sending it. Long threads cost tokens on every call, so trim them somewhere.

> [!WARNING]
> A thread is replayed as context, and `Message` includes `SystemMessage` — so anything that can write to your conversation store can put instructions in front of the model. Treat stored history as untrusted input: restrict who can write it, and don't persist a `SystemMessage` you didn't author.

> [!NOTE]
> Prism ships the interface, not an implementation — no migrations, no tables, no config. What storage you use is up to you. `withThread()` works on both `Prism::text()` and `Prism::structured()`.

## Generation Parameters

Fine-tune your generations with various parameters:

`withMaxTokens`

Maximum number of tokens to generate.

`usingTemperature`

Temperature setting.

The value is passed through to the provider. The range depends on the provider and model. For most providers, 0 means almost deterministic results, and higher values mean more randomness.

> [!TIP]
> It is recommended to set either temperature or topP, but not both.

`usingTopP`

Nucleus sampling.

The value is passed through to the provider. The range depends on the provider and model. For most providers, nucleus sampling is a number between 0 and 1. E.g. 0.1 would mean that only tokens with the top 10% probability mass are considered.

> [!TIP]
> It is recommended to set either temperature or topP, but not both.

`usingTopK`

Top-K sampling.

The value is passed through to the provider. Top-K sampling considers only the K most likely tokens. For example, a topK of 40 means only the top 40 tokens are considered for sampling. This parameter is supported by providers such as Gemini and Anthropic.

> [!TIP]
> It is recommended to set either temperature or topK, but not both.

`withClientOptions`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to pass any of Guzzles [request options](https://docs.guzzlephp.org/en/stable/request-options.html) e.g. `->withClientOptions(['timeout' => 30])`.

`withClientRetry`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to set [retries](https://laravel.com/docs/11.x/http-client#retries) e.g. `->withClientRetry(3, 100)`.

`usingProviderConfig`

This allows for complete or partial override of the providers configuration. This is great for multi-tenant applications where users supply their own API keys. These values are merged with the original configuration allowing for partial or complete config override.

## Response Handling

The response object provides rich access to the generation results:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Access the generated text
echo $response->text;

// Check why the generation stopped
echo $response->finishReason->name;

// Get token usage statistics
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// Access the raw API response data
$rawResponse = $response->raw;

// For multi-step generations, examine each step
foreach ($response->steps as $step) {
    echo "Step text: {$step->text}";
    echo "Step tokens: {$step->usage->completionTokens}";
    // Access raw response for individual steps
    $stepRawResponse = $step->raw;
}

// Access message history
foreach ($response->responseMessages as $message) {
    if ($message instanceof AssistantMessage) {
        echo $message->content;
    }
}
```

### Understanding Token Usage

The `Usage` value object exposes these token counts:

| Property | Meaning |
|---|---|
| `promptTokens` | Input tokens, **excluding** cached (prompt-cache) tokens |
| `completionTokens` | Output tokens |
| `cacheReadInputTokens` | Input tokens served from the provider's prompt cache |
| `cacheWriteInputTokens` | Input tokens written to the prompt cache |
| `thoughtTokens` | Reasoning / thinking tokens, where the provider reports them |
| `cost` | Provider-reported cost, where available |

Prism normalizes `promptTokens` to **exclude** cached input tokens across every
provider that exposes them, so `promptTokens + cacheReadInputTokens` is the
total input billed. This is consistent everywhere.

> [!IMPORTANT]
> **`completionTokens` and reasoning tokens are not yet normalized across providers.**
> Most providers (OpenAI, Anthropic, and the OpenAI-compatible providers)
> report `completionTokens` **inclusive** of `thoughtTokens` — the reasoning
> tokens are part of the output count, and `thoughtTokens` breaks out how many
> of them were reasoning. **Gemini and Vertex** report `completionTokens`
> **exclusive** of reasoning (`thoughtTokens` is separate and additive).
>
> If you compute cost or output length from `completionTokens` for a
> reasoning-enabled model, account for this difference — e.g. for a
> provider-agnostic "total output tokens" figure use
> `completionTokens + (thoughtTokens excluded ? thoughtTokens : 0)`, or read
> the provider's `raw` usage block directly.

## Handling Completions with Callbacks

Need to perform actions after text generation completes? Pass a callback directly to `asText()` to handle the response without interrupting the return flow. This is perfect for persisting conversations, tracking analytics, or logging AI interactions.

### Basic Example

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Explain Laravel middleware')
    ->asText(function (PendingRequest $request, Response $response) {
        // Save the conversation after generation completes
        ConversationLog::create([
            'content' => $response->text,
            'role' => 'assistant',
            'tool_calls' => $response->toolCalls,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
        ]);
    });

// Response is still returned normally
echo $response->text;
```

The callback receives the `PendingRequest` and complete `Response` object, giving you access to the full response including text, tool calls, tool results, and usage statistics.

## Error Handling

Remember to handle potential errors in your generations:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
        ->withPrompt('Generate text...')
        ->asText();
} catch (PrismException $e) {
    Log::error('Text generation failed:', ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    Log::error('Generic error:', ['error' => $e->getMessage()]);
}
```
