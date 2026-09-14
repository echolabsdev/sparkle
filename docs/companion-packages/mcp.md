# Prism MCP

**Tools from a server you don't own, offered to your model as ordinary Prism
tools** — across a trust boundary you can see.

```bash
composer require particle-academy/prism-mcp
```

```php
use Prism\Mcp\Facades\PrismMcp;

$tools = PrismMcp::server('github')->tools();

Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5')
    ->withTools($tools)
    ->withPrompt('Which of our open PRs touch the billing module?')
    ->asText();
```

> [!WARNING]
> **Status: first slice.** The client, over Streamable HTTP, speaking MCP
> revision `2026-07-28`. stdio transport, OAuth, and the legacy protocol eras
> are deliberately not here yet — the package README lists them as a plan.

## Read this part

When you connect to an MCP server, you are not fetching data. You are letting a
third party **write into your model's instructions**, and then letting your model
**act on what they write back**.

Tool names, descriptions, parameter descriptions, enum values, annotations and
every tool *result* are authored by whoever runs that server, and all of them
reach your model as text it treats as authoritative.

Two things make this different from untrusted input your app already handles:

**It's instructions, not data.** You escape user input before HTML and bind it
before SQL. There's no equivalent escape for text reaching a language model,
because instruction and data are the same channel.

**It can change under you.** A tool a third party publishes changes whenever
they like, between two calls. A server can publish a benign `search` tool, wait
to be adopted, then rewrite its description. Reviewing a tool once doesn't
prevent that.

And an injected instruction isn't confined to that server — every *other* tool
in the same run is available to it, including your own database and mail tools.

## Nothing reaches the model until you say so

**A server with no trust declaration refuses, before any request is sent** — so
a misconfigured app never even tells the server it has an audience.

```php
// config/prism-mcp.php
'github' => [
    'url' => env('MCP_GITHUB_URL'),
    'trust' => [
        'tools' => ['search_repositories', 'get_file_contents'],
    ],
],
```

Or, when you genuinely accept whatever that server publishes today and next week
— reasonable for one you operate yourself:

```php
'trust' => ['tools' => '*'],
```

There's no way to skip this. The ad-hoc form people reach for when trying a
server out refuses just as firmly, because exempting the exploratory path puts
the hole exactly where the exploring happens.

## Pin a definition and a rewrite is refused

Opt-in, per tool. Record the digest of a definition you've actually read:

```php
'trust' => [
    'tools' => ['search_repositories'],
    'pins' => ['search_repositories' => 'sha256:…'],
],
```

Get the digests with the artisan command:

```bash
php artisan prism-mcp:pins github
```

The digest covers name, title, description and input schema — everything the
model will read. It excludes annotations, which the spec already tells clients
to distrust, and sorts keys recursively so a server reordering its JSON doesn't
read as a rewritten tool.

This is the one defence against a rewrite that actually holds, because it
doesn't depend on recognising malice — only on noticing change.

## Results are bounded and framed

The discussion around MCP focuses on tool *descriptions*. The **result** path is
worse and gets less attention: a description is read once at discovery, while a
result arrives mid-run, already framed as the trusted output of a tool the model
chose to call.

- **Bounded.** Over `max_result_bytes` (256 KB default) the call is **refused,
  not truncated** — a silently cut result reads to a model as a complete one.
- **Framed.** Wrapped in a delimiter naming the source server, with a **per-call
  random nonce**, so a server that learns the delimiter can't close it early.
- **Filterable.** `->filteringResults(fn ($text, $server, $tool) => …)`.

The **error** path gets identical treatment, because `isError: true` is the same
channel from an attacker's point of view.

## Interoperability

> [!WARNING]
> This client speaks `2026-07-28` **only**, and `laravel/mcp` `v1.0.0-beta.1`
> implements the previous revision — `initialize`, then a session. So a
> `laravel/mcp` server is **not reachable from this client** until `laravel/mcp`
> ships `2026-07-28`.
>
> A client and a server in one estate read as interoperable, so it's said here
> rather than left to be discovered.

## Which package for which direction

They aren't alternatives — they're the two ends of the protocol.

| You are… | Use |
|---|---|
| **consuming** a server you don't own | `prism-mcp` |
| **exposing** your own tools over MCP | `laravel/mcp` |
| offering your own tools to your own Prism agent | neither — `Tool::make()` |

`prism-mcp` will not grow a server direction. A package whose whole
justification is *what you must not trust about the other end* has no business
also being the other end.

> [!NOTE]
> `prism-php/relay` was the previous answer to consuming MCP servers. It's
> superseded — this package declares `replace`. Relay hardcodes protocol
> `2024-11-05`, guesses a tool's calling convention from its parameter names,
> and has no trust boundary of any kind.
