# Structured Output

Want your AI responses as neat and tidy as a Marie Kondo-approved closet? Structured output lets you define exactly how you want your data formatted, making it perfect for building APIs, processing forms, or any time you need data in a specific shape.

## Quick Start

Here's how to get structured data from your AI:

> [!IMPORTANT]
> **Schema Requirement for OpenAI**: When using OpenAI's structured output (especially strict mode), the root schema must be an `ObjectSchema`. Other schema types (StringSchema, NumberSchema, etc.) can only be used as properties within an ObjectSchema, not as the top-level schema. Other providers may have different requirements.

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

// Access your structured data
$review = $response->structured;
echo $review['title'];    // "Inception"
echo $review['rating'];   // "5 stars"
echo $review['summary'];  // "A mind-bending..."
```

> [!TIP]
> This is just a basic example of schema usage. Check out our [dedicated schemas guide](/core-concepts/schemas) to learn about all available schema types, nullable fields, and best practices for structuring your data.

## Understanding Output Modes

Different AI providers handle structured output in two main ways:

1. **Structured Mode**: Some providers support strict schema validation, ensuring responses perfectly match your defined structure.
2. **JSON Mode**: Other providers simply guarantee valid JSON output that approximately matches your schema.

> [!NOTE]
> Check your provider's documentation to understand which mode they support. Provider support can vary by model, so always verify capabilities for your specific use case.

## Provider-Specific Options

Providers may offer additional options for structured output:

### OpenAI: Strict Mode
OpenAI supports a "strict mode" for even tighter schema validation:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::structured()
    ->withProviderOptions([
        'schema' => [
            'strict' => true
        ]
    ])
    // ... rest of your configuration
```

### Qwen: JSON Schema Mode
Qwen supports two modes for structured output through DashScope's `response_format` parameter:

- **JSON Object mode** (default): Ensures valid JSON output. Supported by most Qwen models.
- **JSON Schema mode**: Strictly enforces the schema structure. Supported by `qwen3-max`, `qwen-plus`, `qwen-flash` series and later models.

To use JSON Schema mode for strict schema validation:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StructuredMode;

$response = Prism::structured()
    ->using(Provider::Qwen, 'qwen-plus')
    ->usingStructuredMode(StructuredMode::Structured)
    ->withSchema($schema)
    ->withPrompt('Extract user information')
    ->asStructured();
```

> [!NOTE]
> JSON Schema mode is only supported by newer models. If you use a model that does not support it, use the default JSON Object mode (or `StructuredMode::Auto`) instead. Check the [Qwen structured output documentation](https://www.alibabacloud.com/help/en/model-studio/qwen-structured-output) for the full list of supported models.

### Anthropic: Tool Calling Mode
Anthropic doesn't have native structured output, but Prism provides two approaches. For more reliable JSON parsing, especially with complex content or non-English text, use tool calling mode:
### Anthropic
Anthropic uses native structured outputs by default (Claude Sonnet 4.5+), providing guaranteed schema compliance through constrained decoding. For older models, you can use tool calling mode as a fallback:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::structured()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withPrompt('天氣怎麼樣？應該穿什麼？') // Chinese text with potential quotes
    ->withProviderOptions(['use_tool_calling' => true])
    ->asStructured();
```

**When to use tool calling mode with Anthropic:**
- Using older models that don't support native structured outputs
- Working with non-English content that may contain quotes on older models
- When you need to combine structured output with custom tools

> [!NOTE]
> Tool calling mode cannot be used with Anthropic's citations feature.

> [!TIP]
> Check the provider-specific documentation pages for additional options and features that might be available for structured output.

## Response Handling

When working with structured responses, you have access to both the structured data and metadata about the generation:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::structured()
    ->withSchema($schema)
    ->asStructured();

// Access the structured data as a PHP array
$data = $response->structured;

// Get the raw response text if needed
echo $response->text;

// Check why the generation stopped
echo $response->finishReason->name;

// Get token usage statistics
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// Access the raw API response data
$rawResponse = $response->raw;
```

> [!TIP]
> Always validate the structured data before using it in your application:
```php
if ($response->structured === null) {
    // Handle parsing failure
}

if (!isset($response->structured['required_field'])) {
    // Handle missing required data
}
```

## Common Settings

Structured output supports several configuration options to fine-tune your generations:

### Model Configuration
- `maxTokens` - Set the maximum number of tokens to generate
- `temperature` - Control output randomness (provider-dependent)
- `topP` - Alternative to temperature for controlling randomness (provider-dependent)

### Input Methods
- `withPrompt` - Single prompt for generation
- `withMessages` - Message history for more context
- `withSystemPrompt` - System-level instructions

### Request Configuration 
- `withClientOptions` - Set HTTP client options (e.g., timeouts)
- `withClientRetry` - Configure automatic retries on failures
- `usingProviderConfig` - Override provider configuration
- `withProviderOptions` - Set provider-specific options

See the [Text Generation](./text-generation.md) documentation for comparison with standard text generation capabilities.

## Combining Structured Output with Tools

You can combine structured output with tools to gather data before returning a structured response. This lets the AI call functions to fetch information, then format the results according to your schema.

### Basic Example

Here's a simple example that uses a weather tool to gather data, then returns structured output:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    name: 'weather_analysis',
    description: 'Analysis of weather conditions',
    properties: [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    requiredFields: ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string =>
        "Weather in {$location}: 72°F, sunny"
    );

$response = Prism::structured()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);
// ['summary' => '...', 'recommendation' => '...']
```

> [!IMPORTANT]
> When using tools with structured output, you must set `maxSteps` to at least 2. The AI needs multiple steps: one to call tools, and another to return the structured result.

### Multiple Tools

You can provide multiple tools for the AI to use:

```php
$schema = new ObjectSchema(
    name: 'game_analysis',
    description: 'Analysis of game time and weather',
    properties: [
        new StringSchema('game_time', 'The time of the game'),
        new StringSchema('weather_summary', 'Summary of weather conditions'),
        new StringSchema('recommendation', 'Recommendation on what to wear'),
    ],
    requiredFields: ['game_time', 'weather_summary', 'recommendation']
);

$tools = [
    Tool::as('get_weather')
        ->for('Get current weather for a location')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string =>
            "Weather in {$city}: 45°F and cold"
        ),
    Tool::as('search_games')
        ->for('Search for game times in a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string =>
            'The Tigers game is at 3pm in Detroit'
        ),
];

$response = Prism::structured()
    ->using('openai', 'gpt-4o')
    ->withSchema($schema)
    ->withTools($tools)
    ->withMaxSteps(5)
    ->withPrompt('What time is the Tigers game today in Detroit and should I wear a coat?')
    ->asStructured();
```

### Response Handling

When using tools with structured output, the response includes both the structured data and tool execution details:

```php
// Access final structured data
$data = $response->structured;

// Access all tool calls across all steps
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
    echo "Arguments: " . json_encode($toolCall->arguments()) . "\n";
}

// Access tool results
foreach ($response->toolResults as $result) {
    echo "Tool: {$result->toolName}\n";
    echo "Result: {$result->result}\n";
}

// Inspect individual steps
foreach ($response->steps as $step) {
    echo "Step finish reason: {$step->finishReason->name}\n";

    if ($step->toolCalls) {
        echo "Tools called: " . count($step->toolCalls) . "\n";
    }

    if ($step->structured) {
        echo "Contains structured data\n";
    }
}
```

> [!NOTE]
> Only the final step contains structured data. Intermediate steps contain tool calls and tool results, but no structured output.

For more information about tools and function calling, see the [Tools & Function Calling](./tools-function-calling.md) documentation.

> [!IMPORTANT]
> Always validate the structured response before using it in your application, as different providers may have varying levels of schema adherence.
