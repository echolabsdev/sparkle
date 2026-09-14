# Telemetry

Prism can emit a neutral, structured **telemetry event stream** across the full
generation lifecycle — generation started, each step, each tool call, and
generation completed or failed — as plain Laravel events. It is **off by
default** and a complete no-op until you enable it, so it never adds overhead
you didn't ask for.

Use it to trace and measure generations — latency, token usage, cost, finish
reasons, tool calls — wired into your own logging and metrics, or exported to an
OpenTelemetry backend such as Arize Phoenix with the companion bridge.

## Enabling telemetry

Telemetry is disabled by default. Turn it on with an environment variable:

```env
PRISM_TELEMETRY_ENABLED=true
```

The full configuration block in `config/prism.php`:

```php
'telemetry' => [
    // Master switch. When false, telemetry is a complete no-op: no contexts
    // are minted and no events are dispatched.
    'enabled' => env('PRISM_TELEMETRY_ENABLED', false),

    // Include prompts, completions, and tool arguments in payloads. Off by
    // default — this data can contain PII. Only enable where the sink is trusted.
    'capture_content' => env('PRISM_TELEMETRY_CAPTURE_CONTENT', false),

    // Bounds so telemetry can never turn a streaming response into unbounded memory.
    'content_max_length' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_LENGTH', 65_536),
    'content_max_items' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_ITEMS', 256),
],
```

## The events

All telemetry events live in the `Prism\Prism\Events\Telemetry` namespace and
are dispatched through Laravel's event system, so you consume them with ordinary
listeners.

| Event | Dispatched when | Key payload (beyond `$context`) |
|---|---|---|
| `GenerationStarted` | a generation begins | `$request` |
| `StepCompleted` | each step finishes | `$finishReason`, `$usage`, `$rateLimits`, `$step` |
| `ToolInvoked` | a tool call resolves | `$toolName`, `$toolCallId`, `$durationMs`, `$toolCall`, `$toolResult` |
| `GenerationCompleted` | the generation finishes | `$durationMs`, `$finishReason`, `$usage`, `$rateLimits`, `$response` |
| `GenerationFailed` | the generation throws | `$durationMs`, `$exception` |

Every event carries a `TelemetryContext` (see [below](#the-telemetry-context))
that ties the whole lifecycle together.

## Listening

Subscribe like any Laravel event:

```php
use Illuminate\Support\Facades\Event;
use Prism\Prism\Events\Telemetry\GenerationCompleted;

Event::listen(function (GenerationCompleted $event): void {
    logger()->info('prism.generation', [
        'trace_id' => $event->context->traceId,
        'provider' => $event->context->provider,
        'model' => $event->context->model,
        'duration_ms' => $event->durationMs,
        'finish_reason' => $event->finishReason?->value,
        'prompt_tokens' => $event->usage?->promptTokens,
        'completion_tokens' => $event->usage?->completionTokens,
        'rate_limits' => array_map(fn ($limit) => $limit->toArray(), $event->rateLimits),
    ]);
});
```

For anything more involved, register a listener class in your application's event
service provider.

## The telemetry context

`Prism\Prism\Telemetry\TelemetryContext` is the correlation object shared by
every event of a single generation:

| Property | Description |
|---|---|
| `traceId` | stable id for the whole generation — join every event on this |
| `operation` | a `TelemetryOperation` describing the kind of call |
| `provider` / `model` | the provider key and model id |
| `stepIndex` / `toolIndex` | ordinals nesting steps and tool calls under the generation |
| `userId` / `sessionId` | your optional metadata (see below) |
| `startedAt` | monotonic start; `elapsedMs()` returns elapsed time |

## Attaching user & session metadata

Correlate telemetry with your own users and conversations using the
`withTelemetryMetadata()` builder method — the values flow onto every event's
context:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withTelemetryMetadata(userId: (string) $user->id, sessionId: $conversation->id)
    ->withPrompt('Summarise this thread')
    ->asText();
```

Every telemetry event for that call now carries `$context->userId` and
`$context->sessionId`, so you can group traces per user or per conversation in
your backend.

## Capturing prompt & completion content

By default telemetry records **structure and metrics only** — timings, token
usage, finish reasons, model, and the provider's rate-limit buckets — never the
prompt or completion text, because that content can contain PII.

`capture_content` gates **content and nothing else**. `$usage` and `$rateLimits`
are always populated: a token count and a quota bucket are numbers the provider
reported about the call, and quota headroom is precisely the signal you want
before you hit a 429 rather than after it.

Opt in with `capture_content`, and only where the telemetry sink is trusted:

```env
PRISM_TELEMETRY_CAPTURE_CONTENT=true
```

Captured content is bounded so telemetry can never turn a streaming response into
unbounded process memory:

- `content_max_length` (default `65_536`) caps per-item text length.
- `content_max_items` (default `256`) caps how many message items are captured.

### Attachments

Captured messages can hold images, documents, audio or video. **Their bytes are not
captured by default.** A media part keeps its kind, mime type, file id and filename,
and its `base64` is replaced by `omitted_bytes`, the size of what was left out:

```json
{"kind": "image", "url": null, "base64": null, "mime_type": "image/png", "file_id": null, "filename": null, "omitted_bytes": 48213}
```

To capture the bytes too, where your telemetry sink is trusted with your users'
files:

```env
PRISM_TELEMETRY_CAPTURE_MEDIA=true
```

Only media Prism serialises is recognised. Bytes a user pastes into a prompt as text
are text, and `capture_content` alone decides whether they are captured.

## Exporting to OpenTelemetry / Arize Phoenix

Because the events above are provider-neutral, any exporter can consume them. The
companion package
[`particle-academy/prism-opentelemetry`](https://github.com/Particle-Academy/prism-opentelemetry)
subscribes to them and emits standard **OpenTelemetry** spans — a root span per
generation, child spans per step and per tool call, with token, cost, model, and
finish-reason attributes, in both the GenAI and OpenInference conventions — over
OTLP to [Arize Phoenix](https://phoenix.arize.com), Jaeger, Tempo, or any OTLP
backend.

```bash
composer require particle-academy/prism-opentelemetry
```

With telemetry enabled and the bridge installed, your Prism generations render as
rich, nested traces — no changes to your generation code beyond flipping the
telemetry switch.
