# Requesty

Requesty provides access to multiple AI models through a single OpenAI-compatible API. This provider allows you to use various models from different providers through Requesty's routing system.

## Configuration

Add your Requesty configuration to `config/prism.php`:

```php
'providers' => [
    'requesty' => [
        'api_key' => env('REQUESTY_API_KEY'),
        'url' => env('REQUESTY_URL', 'https://router.requesty.ai/v1'),
        'site' => [
            'http_referer' => env('REQUESTY_SITE_HTTP_REFERER'),
            'x_title' => env('REQUESTY_SITE_X_TITLE'),
        ],
    ],
],
```

## Environment Variables

Set your Requesty API key and URL in your `.env` file:

```env
REQUESTY_API_KEY=your_api_key_here
REQUESTY_URL=https://router.requesty.ai/v1
REQUESTY_SITE_HTTP_REFERER=https://your-site.example
REQUESTY_SITE_X_TITLE="Your Site Name"
```

You can create an API key at [https://app.requesty.ai/api-keys](https://app.requesty.ai/api-keys).

## Usage

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Requesty, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a story about AI.')
    ->generate();

echo $response->text;
```

### Structured Output

> [!NOTE]
> Requesty uses OpenAI-compatible structured outputs. For strict schema validation, the root schema should be an `ObjectSchema`.

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
    ->using(Provider::Requesty, 'openai/gpt-4-turbo')
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
    ->using(Provider::Requesty, 'openai/gpt-4-turbo')
    ->withPrompt('What is the weather like in New York?')
    ->withTools([$weatherTool])
    ->generate();

echo $response->text;
```

### Multimodal Prompts

Requesty keeps the OpenAI content-part schema, so you can mix text and images inside a single user turn.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::Requesty, 'openai/gpt-4o-mini')
    ->withPrompt('Describe the key trends in this diagram.', [
        Image::fromLocalPath('storage/charts/retention.png'),
    ])
    ->generate();

echo $response->text;
```

> [!TIP]
> `Image` value objects are serialized into the `image_url` entries that Requesty expects, so you can attach multiple images or pair them with plain text in the same message.

### Documents

Requesty supports sending documents (PDFs) to compatible models:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Requesty, 'anthropic/claude-sonnet-4')
    ->withPrompt('Summarize this document.', [
        Document::fromUrl('https://example.com/report.pdf', 'report.pdf'),
    ])
    ->generate();

echo $response->text;
```

> [!TIP]
> `Document` value objects support URLs and base64-encoded content. File IDs and chunks are not supported via Requesty.

### Videos

Requesty supports sending video files to compatible models (like Gemini). Videos can be provided as URLs or base64-encoded content:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Video;

$response = Prism::text()
    ->using(Provider::Requesty, 'google/gemini-3-flash-preview')
    ->withPrompt('Describe what happens in this video.', [
        Video::fromLocalPath('/path/to/video.mp4'),
    ])
    ->generate();

echo $response->text;
```

> [!NOTE]
> Video support varies by model. Check your model's capabilities before relying on video input.

### Streaming

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::Requesty, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a long story about AI.')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

> [!WARNING]
> Mid-stream failures may propagate as normal SSE payloads with `error` details while the HTTP status remains 200. Make sure to inspect each chunk for an `error` field so you can surface failures to the caller and stop reading the stream.

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
    ->using(Provider::Requesty, 'openai/gpt-4-turbo')
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

Some models (like OpenAI's o1 series) support reasoning tokens that show the model's thought process:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::Requesty, 'openai/o1-preview')
    ->withPrompt('Solve this complex math problem: What is the derivative of x^3 + 2x^2 - 5x + 1?')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::ThinkingDelta) {
        // This is the model's reasoning/thinking process
        echo "Thinking: " . $event->delta . "\n";
    } elseif ($event->type() === StreamEventType::TextDelta) {
        // This is the final answer
        echo $event->delta;
    }
}
```

#### Reasoning Effort

Control how much reasoning the model performs before generating a response using the `reasoning` parameter. The way this is structured depends on the underlying model you are calling:

```php
$response = Prism::text()
    ->using(Provider::Requesty, 'openai/gpt-5-mini')
    ->withPrompt('Write a PHP function to implement a binary search algorithm with proper error handling')
    ->withProviderOptions([
        'reasoning' => [
            'effort' => 'high',  // Can be "high", "medium", or "low" (OpenAI-style)
            'max_tokens' =>  2000, // Specific token limit (Gemini / Anthropic-style)

            // Optional: Default is false. All models support this.
            'exclude' => false, // Set to true to exclude reasoning tokens from response
            // Or enable reasoning with the default parameters:
            'enabled' => true // Default: inferred from `effort` or `max_tokens`
        ]
    ])
    ->asText();
```

### Provider Routing & Advanced Options

Use `withProviderOptions()` to forward Requesty-specific controls such as model preferences or sampling parameters. Prism automatically forwards the native request values for `temperature`, `top_p`, and `max_tokens`, so you can continue tuning them through the usual Prism API without duplicating them in `withProviderOptions()`.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Requesty, 'openai/gpt-4o')
    ->withPrompt('Draft a concise product changelog entry.')
    ->withProviderOptions([
        'models' => [
            'anthropic/claude-sonnet-4.5',
            'openai/gpt-4o-mini',
        ],
        'top_k' => 40,
    ])
    ->generate();

echo $response->text;
```

> [!IMPORTANT]
> The values you supply here are passed directly to Requesty. Consult the [Requesty documentation](https://requesty.ai) for the full list of supported keys.

## Available Models

Requesty supports many models from different providers using the `provider/model` naming convention. Some popular options include:

- `openai/gpt-4o`
- `anthropic/claude-sonnet-4.5`
- `google/gemini-2.5-flash`
- `deepseek/deepseek-chat`

Visit [Requesty](https://requesty.ai) for a complete list of available models.

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

For detailed API documentation, visit [Requesty's documentation](https://requesty.ai). Manage your API keys at [https://app.requesty.ai/api-keys](https://app.requesty.ai/api-keys).

## Error Handling

The Requesty provider includes standard error handling for common issues:

- Rate limiting
- Request too large
- Provider overload
- Invalid API key

Errors are automatically mapped to appropriate Prism exceptions for consistent error handling across all providers.
