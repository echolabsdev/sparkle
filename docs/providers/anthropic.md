# Anthropic
## Configuration

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
    // Include beta strings as a comma separated list (e.g. output-128k-2025-02-19, code-execution-2025-05-22).
    'anthropic_beta' => env('ANTHROPIC_BETA', null),
]
```
## Prompt caching

Anthropic's prompt caching feature allows you to drastically reduce latency and your API bill when repeatedly re-using blocks of content within five minutes or one hour of each other, depending on the Anthropic compatible TTL option you provide.

There are two ways to enable prompt caching:
- Automatic caching
- Explicit cache breakpoints

To enable automatic caching, simply add a single cache_control field at the top level of your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
    ])
    ->withTools([
        Tool::as('cache me')
    ])
    ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']])
    ->asText();
```

We support Anthropic explicit cache breakpoints on:

- System Messages (text only)
- User Messages (Text, Image and PDF (pdf only))
- Assistant Messages (text only)
- Tools

The API for enabling prompt caching is the same for all, enabled via the `withProviderOptions()` method. Where a UserMessage contains both text and an image or document, both will be cached.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '1h'])
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache me')
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();
```

If you prefer, you can use the `AnthropicCacheType` Enum like so:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

(new UserMessage('I am a long re-usable user message.'))->withProviderOptions(['cacheType' => AnthropicCacheType::ephemeral])
```
**Important:** To enable prompt caching:
- System messages must use `withSystemPrompt()` or `withSystemPrompts()` (Anthropic does not allow SystemMessages in the messages array)
- User and Assistant messages must use `withMessages()`
- Tools use `withTools()`
- All message types support caching via `withProviderOptions(['cacheType' => 'ephemeral'])`
- You cannot use `withPrompt()` for caching as it doesn't allow adding provider options to individual messages
- Anthropic supports two TTL options: `5m` (default) or `1h`. You can leave the `cacheTtl` unset and Anthropic will use the default TTL of `5m`.

### Tool result caching

In addition to caching prompts and tool definitions, Prism supports caching tool results. This is particularly useful when making multiple tool calls where results might be referenced repeatedly.

To enable tool result caching, use the `tool_result_cache_type` provider option on your request:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-20241022')
    ->withMaxSteps(30)
    ->withTools([new WeatherTool()])
    ->withProviderOptions([
        'tool_result_cache_type' => 'ephemeral'
    ])
    ->withPrompt('Check the weather in New York, London, Tokyo, Paris, and Sydney')
    ->asText();
```

When multiple tool results are returned, Prism automatically applies caching to only the last result, which caches all preceding results as well. This avoids Anthropic's 4-cache-breakpoint limitation.

Please ensure you read Anthropic's [prompt caching documentation](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching), which covers some important information on e.g. minimum cacheable tokens and message order consistency.

## Thinking

Anthropic models support extended thinking, where the model reasons before returning its answer. Claude 4.6+ models (Opus 4.6, Sonnet 4.6) use **adaptive thinking** (recommended), where Claude dynamically determines when and how much to think. Older models use manual thinking with a fixed token budget.

Please refer to Anthropic's [adaptive thinking](https://docs.anthropic.com/en/docs/build-with-claude/adaptive-thinking) and [extended thinking](https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking) documentation for important limitations and behaviours when using thinking with caching and/or tools.

### Adaptive thinking (recommended for Claude 4.6+)

Adaptive thinking lets Claude decide when and how much to think based on the complexity of each request:

```php
use Prism\Prism\Facades\Prism;

Prism::text()
    ->using('anthropic', 'claude-sonnet-4-6')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
    ->asText();
```

Use the `effort` parameter to guide how much thinking Claude does:

```php
Prism::text()
    ->using('anthropic', 'claude-sonnet-4-6')
    ->withPrompt('Analyze the trade-offs between microservices and monolithic architectures')
    ->withProviderOptions([
        'thinking' => ['type' => 'adaptive'],
        'effort' => 'high',
    ])
    ->asText();
```

Available effort levels:

| Level    | Description                                                              |
|----------|--------------------------------------------------------------------------|
| `max`    | Maximum capability with no constraints on thinking depth. Opus 4.6 only. |
| `high`   | Deep reasoning on complex tasks. This is the default.                    |
| `medium` | Balanced approach with moderate token savings.                           |
| `low`    | Most efficient. Significant token savings with some capability reduction.|

> [!NOTE]
> Setting `effort` to `"high"` produces the same behavior as omitting the parameter entirely.

> [!TIP]
> The `effort` parameter can also be used without thinking enabled, in which case it controls overall token spend for text responses and tool calls.

Works identically with `Prism::structured()`.

### Manual thinking (legacy)

> [!WARNING]
> Manual thinking with `enabled` and `budgetTokens` is deprecated on Opus 4.6 and Sonnet 4.6, and newer models (Opus 4.7 and later, Sonnet 5) refuse it with a 400. Use adaptive thinking on those. Manual thinking is still required for older models (Sonnet 4.5, Opus 4.5, Sonnet 3.7, etc.).

```php
Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['enabled' => true]])
    ->asText();
```

By default Prism sets the thinking budget to `default_thinking_budget` from the Anthropic provider config (`ANTHROPIC_DEFAULT_THINKING_BUDGET`), or, where that isn't set, to the minimum allowed (1024).

You can override the config (or its default) using `withProviderOptions`:

```php
Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions([
        'thinking' => [
            'enabled' => true,
            'budgetTokens' => 2048,
        ],
    ]);
```

Any other `thinking` value is sent to Anthropic as given: for example its own `['type' => 'enabled', 'budget_tokens' => 2048]`, or `['type' => 'adaptive', 'display' => 'summarized']`. `withReasoning(false)` still removes it.

Note that thinking tokens count towards output tokens, so you will be billed for them and your token budget must be less than the max tokens you have set for the request. If you expect a long response, ensure there's enough tokens left for the response — i.e. does (maxTokens - thinkingBudget) leave a sufficient remainder.

### Inspecting the thinking block

Anthropic returns the thinking block with its response. This works identically for both adaptive and manual thinking modes.

You can access it via the additionalContent property on either the Response or the relevant step.

On the Response (easiest if not using tools):

```php
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-6')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
    ->asText();

$response->additionalContent['thinking'];
```

On the Step (necessary if using tools, as Anthropic returns the thinking block on the ToolCall step):

```php
$tools = [...];

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-6')
    ->withTools($tools)
    ->withMaxSteps(3)
    ->withPrompt('What time is the tigers game today and should I wear a coat?')
    ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
    ->asText();

$response->steps->first()->additionalContent->thinking;
```

> [!NOTE]
> With adaptive thinking, Claude may skip thinking for simple queries, in which case no thinking block is returned.

### Extended output mode

Claude Sonnet 3.7 also brings extended output mode which increase the output limit to 128k tokens.

This feature is currently in beta, so you will need to enable to by adding `output-128k-2025-02-19` to your Anthropic anthropic_beta config (see [Configuration](#configuration) above).

## Streaming

Claude supports streaming responses in real-time. All the standard streaming methods work with Anthropic models:

```php
// Stream events
$stream = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Thinking

When using thinking (adaptive or manual), the reasoning process streams separately from the final answer:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta,
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

### Fine-grained tool streaming

Anthropic’s [fine-grained tool streaming](https://docs.anthropic.com/en/docs/agents-and-tools/tool-use/fine-grained-tool-streaming) lets tool-use input arrive incrementally (with lower latency for large arguments) instead of waiting for a complete JSON payload. Enable it on each tool that should use this behavior with the `eager_input_streaming` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['eager_input_streaming' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5-20250929')
    ->withTools([$weatherTool])
    ->withPrompt('What is the weather in San Francisco?')
    ->asStream();
```

Use this together with a streaming entrypoint (`asStream()`, `asEventStreamResponse()`, etc.). While the model is still emitting a tool call, streamed input may be partial JSON; only treat tool arguments as final once the stream indicates the tool input is complete.

For complete streaming documentation including Vercel Data Protocol and WebSocket broadcasting, see [Streaming Output](/core-concepts/streaming-output).

## Documents

Anthropic supports PDF, text and markdown documents. Note that Anthropic uses vision to process PDFs under the hood, and consequently there are some limitations detailed in their [feature documentation](https://docs.anthropic.com/en/docs/build-with-claude/pdf-support).

See the [Documents](/input-modalities/documents.html) on how to get started using them.

Anthropic also supports "custom content documents", separately documented below, which are primarily for use with citations.

### Custom content documents

Custom content documents are primarily for use with citations (see below), if you need citations to reference your own chunking strategy.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(["The grass is green.", "Flamingos are pink.", "The sky is blue."])
            ]
        )
    ])
    ->asText();
```

## Citations

Prism supports [Anthropic's citations feature](https://docs.anthropic.com/en/docs/build-with-claude/citations) for both text and structured.

Please note that citations cannot be used with native structured output mode (the default). If you need citations with structured output, use tool calling mode via `withProviderOptions(['use_tool_calling' => true, 'citations' => true])`. Note however that citations with tool calling mode can produce unreliable output, so you should implement proper error handling.

## Code execution

Anthropic offers built-in code execution capabilities that allow your AI to run code in a secure environment. This is a provider tool that executes code using Anthropic's infrastructure. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

To enable code execution, you will first need to enable the beta feature.

Either in prism/config.php:

```php
        'anthropic' => [
            ...
            'anthropic_beta' => 'code-execution-2025-05-22',
        ],

```

Or in your env file (assuming config/prism.php reflects the default prism setup):

```
ANTHROPIC_BETA="code-execution-2025-05-22"
```

You may then use code execution as follows:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('anthropic', 'claude-3-5-haiku-latest')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')])
    ->asText();

```

### Enabling citations

Anthropic require citations to be enabled on all documents in a request. To enable them, using the `withProviderOptions()` method when building your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(
                    chunks: ["The grass is green.", "Flamingos are pink.", "The sky is blue."],
                    title: 'The colours of nature',
                    context: 'The go-to textbook on the colours found in nature!'
                )
            ]
        )
    ])
    ->withProviderOptions(['citations' => true])
    ->asText();
```

### Accessing citations

You can access the chunked output with its citations via the additionalContent property on a response, which returns an array of `MessagePartWithCitations`s.

As a rough worked example, let's assume you want to implement footnotes. You'll need to loop through those chunks and (1) re-construct the message with links to the footnotes; and (2) build an array of footnotes to loop through in your frontend.

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$messageChunks = $response->additionalContent['citations'];

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $messageChunk  */
foreach ($messageChunks as $messageChunk) {
    $text .= $messageChunk->outputText;
    
    /** @var Citation $citation */
    foreach ($messageChunk->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'document_title' => $citation->sourceTitle,
            'reference_start' => $citation->sourceStartIndex,
            'reference_end' => $citation->sourceEndIndex
        ];
    
        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';
    
        $footnoteId++;
    }
}
```

Note that when using streaming, Anthropic does not stream citations in the same way. Instead, of building the context as above, yield text to the browser in the usual way and pair text up with the relevant footnote using the `citationIndex` on the text chunk's additionalContent parameter.

## Context management

Anthropic can edit the transcript server-side as a conversation grows — clearing
older tool results, clearing thinking blocks, or compacting. Pass the edits
through `context_management`, along with the beta header:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5')
    ->withMaxSteps(24)
    ->withTools($tools)
    ->withPrompt('Audit every page of the ledger.')
    ->withProviderOptions([
        'anthropic_beta' => 'context-management-2025-06-27',
        'context_management' => [
            'edits' => [[
                'type' => 'clear_tool_uses_20250919',
                'trigger' => ['type' => 'input_tokens', 'value' => 30000],
                'keep' => ['type' => 'tool_uses', 'value' => 3],
            ]],
        ],
    ])
    ->asText();
```

`anthropic_beta` **merges** with any beta features set in your config, rather
than replacing them, and accepts either a comma-separated string or an array. So
adding context management will not switch off another beta you already rely on.

### Reading what was actually cleared

What Anthropic did comes back on `additionalContent`:

```php
$applied = $response->additionalContent['context_management'] ?? null;

foreach ($applied['applied_edits'] ?? [] as $edit) {
    $edit['type'];                  // clear_tool_uses_20250919
    $edit['cleared_tool_uses'];     // 6
    $edit['cleared_input_tokens'];  // 12678
}
```

**The counts live inside each `applied_edits` entry, not on the block above
them.** Reading them one level too high returns nothing and raises nothing — you
get `applied_edits: 1, cleared_input_tokens: 0`, which reads as "the edit ran and
cleared nothing" rather than as a mistake in your own code.

Three states are worth distinguishing, and only two of them are visible if you
check for truthiness alone:

| `additionalContent['context_management']` | meaning |
|---|---|
| absent | the field never reached the API — usually a missing beta header |
| present, `applied_edits` empty | the edit ran and had nothing to clear yet |
| present, `applied_edits` populated | the transcript was edited |

The first is a configuration failure that otherwise looks exactly like a quiet
success: the request succeeds, the bill is unchanged, and nothing reports a
problem.

`cleared_input_tokens` accumulates over a conversation rather than reporting a
per-turn delta.

::: warning Verifying the figure yourself
A before/after comparison of per-step input tokens will **under-report** what was
cleared, because the turn that triggered the edit also grew the transcript by its
own assistant message and a fresh tool result. Subtracting two consecutive step
totals measures the clearing minus that growth. Add the growth back before
concluding the reported number is wrong.
:::

## Considerations
### Message Order

- Message order matters. Anthropic is strict about the message order being:

1. `UserMessage`
2. `AssistantMessage`
3. `UserMessage`

### Structured Output

Prism supports two approaches for structured output with Anthropic models:

#### Native Structured Outputs (Default)

Prism uses Anthropic's native structured outputs by default. This provides guaranteed schema compliance through constrained decoding — no beta header required.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```

**Benefits of native structured outputs:**
- **Always valid JSON**: No more parsing errors or malformed responses
- **Type safe**: Guaranteed field types and required fields

**Limitations:**
- Only available on Claude Sonnet 4.5+ and Claude Opus 4.1+
- Cannot be used with citations
- Some JSON Schema features are not supported (see [Schema Limitations](#schema-limitations))

#### Tool Calling Mode
For older models that don't support native structured outputs, or when dealing with complex content or non-English text that may contain quotes, you can use tool calling mode:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->withProviderOptions(['use_tool_calling' => true])
    ->asStructured();
```

**Benefits of tool calling mode:**
- More reliable JSON parsing, especially with quotes and special characters
- Better handling of non-English content (Chinese, Japanese, etc.)
- Reduced risk of malformed JSON responses
- Compatible with thinking mode

**Limitations:**
- Cannot be used with citations (citations are not supported in tool calling mode)
- Slightly more complex under the hood but identical API usage

#### Combining Custom Tools with Structured Output

You can combine custom tools with structured output to gather data before returning a structured response. This requires tool calling mode to be enabled:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    'weather_analysis',
    'Analysis of weather conditions',
    [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withProviderOptions(['use_tool_calling' => true]) // Required for Anthropic
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);

// Access tool execution details
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}
```

> [!IMPORTANT]
> When using custom tools with structured output on Anthropic, you must:
> - Set `use_tool_calling: true` in provider options
> - Set `maxSteps` to at least 2

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Strict Tool Use

You can enable strict validation for tool inputs, which guarantees that tool parameters exactly match your schema through constrained decoding.

To enable strict mode for a tool, use the `strict` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['strict' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5-20250929')
    ->withTools([$weatherTool])
    ->withPrompt('What is the weather in San Francisco?')
    ->asText();
```

**Benefits of strict tool use:**
- Functions receive correctly-typed arguments every time
- No need to validate tool inputs
- Eliminates runtime errors from type mismatches
- Production-ready agents that work consistently

### Schema Limitations

When using native structured outputs, certain JSON Schema features are not supported by Anthropic's constrained decoding:

**Not Supported:**
- Recursive schemas
- Numerical constraints (`minimum`, `maximum`, `multipleOf`)
- String constraints (`minLength`, `maxLength`)
- Complex regex patterns (lookahead/lookbehind, backreferences)
- External `$ref` definitions

**Supported:**
- All basic types (object, array, string, integer, number, boolean, null)
- `enum` for simple types (strings, numbers, booleans)
- `anyOf` and `allOf` (with limitations)
- `required` and `additionalProperties: false`
- String formats (`date-time`, `email`, `uri`, `uuid`, etc.)

If you use an unsupported feature, Anthropic will return a 400 error with details. For more information, see [Anthropic's structured outputs documentation](https://docs.anthropic.com/en/api/structured-outputs).

## Limitations
### Messages

Most providers' API include system messages in the messages array with a "system" role. Anthropic does not support the system role, and instead has a "system" property, separate from messages.

Therefore, for Anthropic we:
* Filter all `SystemMessage`s out, omitting them from messages.
* Always submit the prompt defined with `->withSystemPrompt()` at the top of the system prompts array.
* Move all `SystemMessage`s to the system prompts array in the order they were declared.

### Images

Does not support `Image::fromURL`
