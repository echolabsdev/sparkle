# OpenRouter

OpenRouter provides access to multiple AI models through a single API. This provider allows you to use various models from different providers through OpenRouter's routing system.

## Configuration

Add your OpenRouter configuration to `config/prism.php`:

```php
'providers' => [
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        'site' => [
            'http_referer' => env('OPENROUTER_SITE_HTTP_REFERER'),
            'x_title' => env('OPENROUTER_SITE_X_TITLE'),
        ],
    ],
],
```

## Environment Variables

Set your OpenRouter API key and URL in your `.env` file:

```env
OPENROUTER_API_KEY=your_api_key_here
OPENROUTER_URL=https://openrouter.ai/api/v1
OPENROUTER_SITE_HTTP_REFERER=https://your-site.example
OPENROUTER_SITE_X_TITLE="Your Site Name"
```

## Usage

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a story about AI.')
    ->generate();

echo $response->text;
```

### Structured Output

> [!NOTE]
> OpenRouter uses OpenAI-compatible structured outputs. For strict schema validation, the root schema should be an `ObjectSchema`.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema('person', 'Person information', [
    new StringSchema('name', 'The person\'s name'),
    new StringSchema('occupation', 'The person\'s occupation'),
]);

$response = Prism::structured()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Generate a person profile for John Doe.')
    ->withSchema($schema)
    ->generate();

echo $response->text;
```

### Tool Calling

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The location to get weather for')
    ->using(function (string $location) {
        return "The weather in {$location} is sunny and 72°F";
    });

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('What is the weather like in New York?')
    ->withTools([$weatherTool])
    ->generate();

echo $response->text;
```

### Multimodal Prompts

OpenRouter keeps the OpenAI content-part schema, so you can mix text and images inside a single user turn.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4o-mini')
    ->withPrompt('Describe the key trends in this diagram.', [
        Image::fromLocalPath('storage/charts/retention.png'),
    ])
    ->generate();

echo $response->text;
```

> [!TIP]
> `Image` value objects are serialized into the `image_url` entries that OpenRouter expects, so you can attach multiple images or pair them with plain text in the same message.

### Documents

OpenRouter supports sending documents (PDFs) to compatible models:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'anthropic/claude-sonnet-4')
    ->withPrompt('Summarize this document.', [
        Document::fromUrl('https://example.com/report.pdf', 'report.pdf'),
    ])
    ->generate();

echo $response->text;
```

> [!TIP]
> `Document` value objects support URLs and base64-encoded content. File IDs and chunks are not supported via OpenRouter.

### Videos

OpenRouter supports sending video files to compatible models (like Gemini). Videos can be provided as URLs or base64-encoded content:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Video;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'google/gemini-3-flash-preview')
    ->withPrompt('Describe what happens in this video.', [
        Video::fromLocalPath('/path/to/video.mp4'),
    ])
    ->generate();

echo $response->text;
```

You can also use YouTube URLs with Gemini models via OpenRouter:

```php
$response = Prism::text()
    ->using(Provider::OpenRouter, 'google/gemini-3-flash-preview')
    ->withPrompt('Summarize this video.', [
        Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
    ])
    ->generate();
```

> [!NOTE]
> Video support varies by model. Check [OpenRouter's models page](https://openrouter.ai/models?input_modalities=video) for models with video input support.

### Streaming

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a long story about AI.')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

> [!NOTE]
> OpenRouter keeps SSE connections alive by emitting comment events such as `: OPENROUTER PROCESSING`. These lines are safe to ignore while parsing the stream.
>
> [!WARNING]
> Mid-stream failures propagate as normal SSE payloads with `error` details and `finish_reason: "error"` while the HTTP status remains 200. Make sure to inspect each chunk for an `error` field so you can surface failures to the caller and stop reading the stream.

### Streaming with Tools

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The location to get weather for')
    ->using(function (string $location) {
        return "The weather in {$location} is sunny and 72°F";
    });

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('What is the weather like in multiple cities?')
    ->withTools([$weatherTool])
    ->asStream();

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::TextDelta => echo $event->delta,
        StreamEventType::ToolCall => echo "Tool called: {$event->toolName}\n",
        StreamEventType::ToolResult => echo "Tool result: " . json_encode($event->result) . "\n",
        default => null,
    };
}
```

### Reasoning/Thinking Tokens

OpenRouter provides a unified `reasoning` API that normalizes reasoning tokens across providers (Anthropic, OpenAI, Gemini, DeepSeek, etc.). Models that support reasoning will return their thought process alongside the response.

#### Reasoning Effort

Control how much reasoning the model performs using the `reasoning` provider option. OpenRouter normalizes the configuration across different underlying providers:

```php
$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-5-mini')
    ->withPrompt('Write a PHP function to implement a binary search algorithm with proper error handling')
    ->withProviderOptions([
        'reasoning' => [
            'effort' => 'high',  // "xhigh", "high", "medium", "low", "minimal", or "none" (OpenAI-style)
            'max_tokens' => 2000, // Specific token limit (Anthropic / Gemini-style)

            // Optional: Default is false. All models support this.
            'exclude' => false, // Set to true to exclude reasoning tokens from response
            // Or enable reasoning with the default parameters:
            'enabled' => true // Default: inferred from `effort` or `max_tokens`
        ]
    ])
    ->asText();
```

> [!NOTE]
> The `effort` and `max_tokens` options are mutually exclusive. Use `effort` for OpenAI-style models and `max_tokens` for Anthropic/Gemini-style models. OpenRouter will translate between the two formats as needed.

#### Inspecting Reasoning Content

Reasoning content is available via the `additionalContent` property on the response or individual steps:

```php
$response = Prism::text()
    ->using(Provider::OpenRouter, 'anthropic/claude-sonnet-4.5')
    ->withPrompt('What is the derivative of x^3 + 2x^2 - 5x + 1?')
    ->withProviderOptions([
        'reasoning' => ['max_tokens' => 4000]
    ])
    ->asText();

// Plaintext reasoning (when available)
$response->additionalContent['reasoning'];

// Structured reasoning details (includes type, format, and provider-specific metadata)
$response->additionalContent['reasoning_details'];

// Reasoning token usage
$response->usage->thoughtTokens;
```

> [!NOTE]
> Some models (like OpenAI's o-series and GPT-5) return encrypted reasoning via `reasoning_details` rather than plaintext `reasoning`. Prism preserves these opaque blocks so they can be passed back for multi-turn reasoning continuity.

#### Streaming with Reasoning

When streaming, reasoning tokens arrive as `ThinkingDelta` events before the text content:

```php
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'anthropic/claude-sonnet-4.5')
    ->withPrompt('Solve this complex math problem step by step.')
    ->withProviderOptions([
        'reasoning' => ['max_tokens' => 4000]
    ])
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::ThinkingDelta) {
        echo "Thinking: " . $event->delta . "\n";
    } elseif ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

### Provider Routing & Advanced Options

Use `withProviderOptions()` to forward OpenRouter-specific controls such as model preferences or sampling parameters. Prism automatically forwards the native request values for `temperature`, `top_p`, and `max_tokens`, so you can continue tuning them through the usual Prism API without duplicating them in `withProviderOptions()`. For transform pipelines, OpenRouter currently documents `"middle-out"` as the primary example—consult the parameter reference for additional context.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4o')
    ->withPrompt('Draft a concise product changelog entry.')
    ->withProviderOptions([
        // https://openrouter.ai/docs/model-routing
        'models' => [
            'anthropic/claude-sonnet-4.5',
            'openai/gpt-4o-mini',
        ],
        'top_k' => 40,
        // Reference: https://openrouter.ai/docs/api-reference/parameters for the full parameter list.
    ])
    ->generate();

echo $response->text;
```

> [!IMPORTANT]
> The values you supply here are passed directly to OpenRouter. Consult the [Parameters reference](https://openrouter.ai/docs/api-reference/parameters) and [Provider Routing guide](https://openrouter.ai/docs/provider-routing) for the full list of supported keys.

The single `model` parameter and the fallback `models` array work together. When both are present, OpenRouter first tries the `model` value, then walks the `models` list in order—exactly as outlined in the [Model Routing guide](https://openrouter.ai/docs/features/model-routing). Fallbacks trigger for moderation flags, context-length errors, rate limits, or provider downtime, and the final `model` field in the response reveals which entry actually served the request (and therefore which pricing tier applies). If you prefer OpenRouter to choose the initial model, set `model` to `openrouter/auto` and still supply a `models` array for explicit overrides when needed. Because metadata is centralized, you can double-check `supported_parameters`, context length, and per-request limits via the [Models API](https://openrouter.ai/docs/overview/models) before rolling out changes.

## Available Models

OpenRouter supports many models from different providers. The [Models API](https://openrouter.ai/docs/overview/models) returns structured metadata—`supported_parameters`, context length, pricing, and more—so you can verify capabilities programmatically before issuing requests. Some popular options include:

- `x-ai/grok-code-fast-1`
- `anthropic/claude-sonnet-4.5`
- `google/gemini-2.5-flash`
- `deepseek/deepseek-chat-v3-0324`
- `z-ai/glm-4.6`
- `tngtech/deepseek-r1t2-chimera:free`
- `qwen/qwen3-coder-30b-a3b-instruct`
- `mistralai/mistral-nemo`

Visit [OpenRouter's models page](https://openrouter.ai/models) for a complete list of available models.

## Features

- ✅ Text Generation
- ✅ Structured Output
- ✅ Tool Calling
- ✅ Multiple Model Support
- ✅ Provider Routing
- ✅ Streaming
- ✅ Reasoning/Thinking Tokens (for compatible models)
- ✅ Image Support
- ✅ Video Support
- ✅ Document Support
- ❌ Embeddings (not yet implemented)
- ❌ Image Generation (not yet implemented)

## API Reference

For detailed API documentation, visit [OpenRouter's API documentation](https://openrouter.ai/docs/api-reference/chat-completion).

## Error Handling

The OpenRouter provider includes standard error handling for common issues:

- Rate limiting
- Request too large
- Provider overload
- Invalid API key

Errors are automatically mapped to appropriate Prism exceptions for consistent error handling across all providers. 
