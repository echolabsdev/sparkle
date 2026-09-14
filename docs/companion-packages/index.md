# Companion Packages

Prism core does one job: it shuttles your application to eighteen LLM provider
APIs and gets out of the way. Everything *agentic* — durable sessions, memory,
sandboxed file access, tools from servers you don't own — lives in a companion
package built on top of it.

That split is deliberate, and it's worth a paragraph because it explains why
your favourite feature isn't in core.

Every capability added to core is a capability all eighteen providers have to
carry, and keep carrying, forever. The cost is invisible on the day it's added
and permanent afterwards. So the question for anything agentic isn't "is this
useful?" — it's "which companion owns it?" Core stays small, the companions move
fast, and you install only what you actually use.

## What's available

| Package | What it gives you | Status |
|---|---|---|
| [**Harness**](/companion-packages/harness) | Durable agent sessions — threads that survive the request | **RC — in testing** |
| [**MCP**](/companion-packages/mcp) | Tools from servers you don't own, behind a visible trust boundary | First slice |
| [**Memory**](/companion-packages/memory) | Persistent context and semantic recall | First slice, not yet on Packagist |
| [**Workspace**](/companion-packages/workspace) | A sandboxed place for an agent to keep its work | First slice, not yet on Packagist |
| **Perplexity** | The Perplexity endpoints that don't fit the provider abstraction | Available |
| **OpenTelemetry** | Prism's telemetry as GenAI-convention spans — see [Telemetry](/advanced/telemetry) | In development |

> [!WARNING]
> These are young packages and they say so. Each README opens with a status note
> listing what works and what's deliberately still missing — read it before you
> build on one. An absence recorded as a plan is very different from an absence
> nobody noticed, and we try hard to keep ours in the first category.

## How they fit together

They compose, and they compose *loosely* — through Prism's own message value
objects rather than through each other:

```
                    ┌─────────────┐
                    │ prism (core)│  providers, text, tools, structured output
                    └──────┬──────┘
                           │
     ┌──────────┬──────────┼──────────┬──────────────┐
     │          │          │          │              │
  Harness    Memory    Workspace     MCP        OpenTelemetry
  sessions   recall     files      remote tools    spans
```

You can use Memory without sessions, sessions without Memory, or both. None of
them requires another, and that's on purpose: a hard dependency between two of
them would take the choice away from you.

Core depends on **none** of them.

## Installing

Four are on Packagist today:

```bash
composer require particle-academy/prism-harness
composer require particle-academy/prism-mcp
composer require particle-academy/prism-perplexity
composer require particle-academy/prism-opentelemetry
```

**Memory** and **Workspace** aren't published yet. Until they are, point Composer
at the repository directly:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Particle-Academy/prism-memory" }
    ]
}
```

> [!NOTE]
> Prism itself is `particle-academy/prism` — the actively maintained fork of
> `prism-php/prism`. The companions are built against it and track its releases.
