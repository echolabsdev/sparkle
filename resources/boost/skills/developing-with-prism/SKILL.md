---
name: developing-with-prism
description: Guide for developing with Prism PHP package - a Laravel package for integrating LLMs. Activate or use when working with Prism features including text generation, structured output, embeddings, image generation, audio processing, streaming, tools/function calling, or any LLM provider integration (OpenAI, Anthropic, Gemini, Mistral, Groq, XAI, DeepSeek, OpenRouter, Ollama, VoyageAI, ElevenLabs). Activate for any Prism-related development tasks.
---

# Developing with Prism

Prism is a Laravel package for integrating Large Language Models (LLMs) into applications with a fluent, expressive and eloquent API.

## Basic Usage Examples

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt('You are an expert mathematician.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->asText();

echo $response->text;
```

### Structured Output

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    name: 'movie_review',
    description: 'A structured movie review',
    properties: [
        new StringSchema('title', 'The movie title'),
        new StringSchema('rating', 'Rating out of 5 stars'),
        new StringSchema('summary', 'Brief review summary')
    ],
    requiredFields: ['title', 'rating', 'summary']
);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSchema($schema)
    ->withPrompt('Review the movie Inception')
    ->asStructured();

$review = $response->structured;
echo $review['title'];
```

### Streaming (Server-Sent Events)

```php
Route::get('/chat', function () {
    return Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet')
        ->withPrompt(request('message'))
        ->asEventStreamResponse();
});
```

### Tools / Function Calling

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string =>
        "Weather in {$location}: 72F, sunny"
    );

$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco?')
    ->asText();
```

### Multi-Modal (Images/Documents)

```php
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'What objects do you see in this image?',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();
```

## Prism Documentation

**IMPORTANT:** Always search the docs before implementing Prism features.

### How to Search

1. **Read a specific doc file directly:**
   ```
   read vendor/particle-academy/prism/docs/core-concepts/text-generation.md
   read vendor/particle-academy/prism/docs/providers/openai.md
   ```

2. **Search for a topic across docs:**
   ```
   grep "streaming" vendor/particle-academy/prism/docs/
   grep "withProviderOptions" vendor/particle-academy/prism/docs/providers/
   ```

3. **Find all doc files:**
   ```
   glob "vendor/particle-academy/prism/docs/**/*.md"
   ```

### Documentation Paths

| Need | Read This File |
|------|----------------|
| Text generation | `docs/core-concepts/text-generation.md` |
| Streaming responses | `docs/core-concepts/streaming-output.md` |
| Tools / function calling | `docs/core-concepts/tools-function-calling.md` |
| Structured JSON output | `docs/core-concepts/structured-output.md` |
| Embeddings | `docs/core-concepts/embeddings.md` |
| Image generation | `docs/core-concepts/image-generation.md` |
| Audio (TTS/STT) | `docs/core-concepts/audio.md` |
| Schema definitions | `docs/core-concepts/schemas.md` |
| Testing | `docs/core-concepts/testing.md` |
| Image input | `docs/input-modalities/images.md` |
| Document input (PDF) | `docs/input-modalities/documents.md` |
| OpenAI options | `docs/providers/openai.md` |
| Anthropic options | `docs/providers/anthropic.md` |
| Other providers | `docs/providers/{provider}.md` |
| Error handling | `docs/advanced/error-handling.md` |

### Source Code Reference

For implementation details:
```
glob "src/**/*.php"
grep "class Tool" src/
```

## Key Patterns

- Use `Prism\Prism\Facades\Prism` facade or `prism()` helper
- Core methods: `Prism::text()`, `Prism::structured()`, `Prism::embeddings()`, `Prism::image()`, `Prism::audio()`
- Chain `->using(Provider::Name, 'model-id')` to specify provider/model
- Finalize with: `->asText()`, `->asStructured()`, `->asStream()`, `->asEventStreamResponse()`, `->asDataStreamResponse()`

## Provider-Specific Options

Use `->withProviderOptions([...])` to pass provider-specific features:

```php
$response = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('Your prompt')
    ->withProviderOptions(['thinking' => ['enabled' => true]])  // Anthropic-specific
    ->asText();
```

**Always search the provider docs first** to find available options for each provider:
- `docs/providers/openai.md` - strict mode, reasoning, image generation options
- `docs/providers/anthropic.md` - thinking mode, prompt caching, citations
- `docs/providers/gemini.md`, `docs/providers/mistral.md`, etc.

## Common Pitfalls

### Wrong Package Name

**NEVER use the old package:** `echolabsdev/prism` is deprecated.

**ALWAYS use:** `particle-academy/prism`

```bash
# Correct
composer require particle-academy/prism

# Wrong - do not use
composer require echolabsdev/prism
```

### Wrong Namespace

**ALWAYS use the `Prism\Prism` namespace** for all Prism classes:

```php
// Correct
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;
use Prism\Prism\Schema\ObjectSchema;

// Wrong - these namespaces do not exist
use EchoLabs\Prism\Prism;
use Prism\Facades\Prism;
```

### Decision Workflow

When working with Prism, follow this pattern:

1. Determine what you need:

   **Text generation?** → Use `Prism::text()`
   **Structured JSON output?** → Use `Prism::structured()`
   **Embeddings?** → Use `Prism::embeddings()`
   **Image generation?** → Use `Prism::image()`
   **Audio (TTS/STT)?** → Use `Prism::audio()`

2. Always read the relevant docs first before implementing.

## Related Packages

- **Prism Relay** - MCP tools integration for Prism. See `references/relay.md`
- **Prism Bedrock** - AWS Bedrock provider: https://github.com/prism-php/bedrock
