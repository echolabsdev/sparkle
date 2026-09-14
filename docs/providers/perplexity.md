# Perplexity

> [!IMPORTANT]
> **Prism now talks to Perplexity's Agent API (`POST /v1/agent`).**
>
> Perplexity retires the Sonar `/chat/completions` endpoints on **2026-09-27**. Prism was
> updated ahead of that date, so your calls keep working across the cut — but there are
> behaviour changes worth knowing about, below.

## Migrating from Sonar

**Your model strings keep working.** Perplexity replaced model slugs with presets, and Prism
translates the retired slugs for you:

| You pass | Prism sends |
|---|---|
| `sonar` | `preset: fast` |
| `sonar-pro` | `preset: low` |
| `sonar-reasoning` | `preset: low` (slug retired upstream) |
| `sonar-reasoning-pro` | `preset: low` |
| `sonar-deep-research` | `preset: medium` |

A preset name given directly (`fast`, `low`, `medium`, `high`, `xhigh`, `wide-research`) is
passed through, and anything else is treated as a real model id — `openai/gpt-5.6-sol` is sent
as `model`, not guessed at as a preset.

### Why `sonar-deep-research` maps to `medium` and not `high`

Perplexity publishes two mappings that disagree, and only on the expensive rows. Their
migration overview suggests `high`; their preset documentation shows the presets were
**renamed**, and the one formerly called *deep-research* is now called *medium*.

Prism follows the rename, because that is the behavioural equivalent. `high` is the tier above
it — a costlier upgrade rather than a like-for-like replacement, and one that returns a
perfectly plausible answer while billing more.

If you want a different tier, say so and it wins:

```php
Prism::text()
    ->using(Provider::Perplexity, 'sonar-deep-research')
    ->withProviderOptions(['preset' => 'high'])
```

### `withSystemPrompt()` replaces the preset's own prompt

A system prompt is sent as the Agent API's `instructions`, and `instructions` **replaces** a
preset's built-in system prompt rather than adding to it. A preset is a model *plus* a prompt
tuned against real workloads, so setting your own discards that half of it. Nothing errors —
the answers just change.

Prism only sends `instructions` when you actually set a system prompt, so presets keep their
own by default.

### Failures arrive as HTTP 200

A failed or cancelled run returns **HTTP 200** with `status: "failed"` or `"cancelled"` and a
populated `error`. Prism branches on the run status and throws a `PrismException` for both, so
you do not have to remember this — but if you read raw responses anywhere, do not trust the
HTTP code alone.

### What comes back

```php
$response->additionalContent['search_results'];   // structured sources, not prose
$response->additionalContent['fetch_url_results'];
$response->additionalContent['resolved_model'];   // which model the preset actually used
```

`resolved_model` is worth reading: a preset can route to a third-party model, and both token
accounting and data-handling decisions depend on knowing which one served the request.

An **empty** `search_results` on a completed run is normal — a preset may answer without
searching — so do not treat a missing source list as an error.

### Cost is reported, not estimated

Perplexity prices each request in its own response, so you get the real figure rather than one
derived from a rate card:

```php
$response->usage->cost;   // e.g. 0.005, or null if the response carried none
```

Only two Prism providers do this — Perplexity and OpenRouter. Everywhere else `cost` is null and
you have to derive it. A `0.0` here is a genuine answer, not a missing one.

The breakdown Perplexity sends alongside the total — input, output and request components — stays
available on the raw response.

> [!NOTE]
> `tools: []` does not disable a preset's built-in tools. Perplexity exposes no public field
> that does.

## Configuration

```php
'perplexity' => [
    'api_key' => env('PERPLEXITY_API_KEY', ''),
    'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
]
```

## Documents

Sonar models support document analysis through file uploads. You can provide files either as URLs to publicly accessible documents or as base64 encoded bytes. Ask questions about document content, get summaries, extract information, and perform detailed analysis of uploaded files in multiple formats including PDF, DOC, DOCX, TXT, and RTF.
- The maximum file size is 50MB. Files larger than this limit will not be processed
- Ensure provided HTTPS URLs are publicly accessible
Check it out the [documentation for more details](https://docs.perplexity.ai/guides/file-attachments)

## Images
Sonar models support image analysis through direct image uploads. You can include images in your API requests to support multi-modal conversations alongside text. Images can be provided either as base64 encoded strings within a data URI or as standard HTTPS URLs.
- When using base64 encoding, the API currently only supports images up to 50 MB per image
- Supported formats for base64 encoded images: PNG (image/png), JPEG (image/jpeg), WEBP (image/webp), and GIF (image/gif)
- When using an HTTPS URL, the model will attempt to fetch the image from the provided URL. Ensure the URL is publicly accessible.

## Considerations
### Message Order

- Message order matters. Perplexity is strict about the message order being:

1. `SystemMessage`
2. `UserMessage`
3. `AssistantMessage`

### Additional fields
Perplexity outputs additional fields in the response, such as `citations`, `search_results`, and the `reasoning` that is extracted from the model response. These fields are exposed in the response object
via the property `additionalFields`. e.g `$response->additionalFields['citations']`.

### Structured Output

Perplexity supports two types of structured outputs: JSON Schema and Regex; but currently Prism only supports JSON Schema.

Here's an example of how to use JSON Schema for structured output:

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
    ->using(Provider::Perplexity, 'sonar-pro')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```