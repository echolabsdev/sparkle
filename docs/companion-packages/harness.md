# Prism Harness

**Durable agent sessions for Laravel** — threads, modes, tool permissions and
subagents, on top of Prism.

> [!WARNING]
> **Release candidate — still in testing.** Conversation persistence and session
> rehydration are implemented and tested. **Modes, permissions and subagents are
> design, not code yet** — see [What isn't built](#what-isn-t-built). Treat the
> API as settling rather than settled, and pin a version.

```bash
composer require particle-academy/prism-harness
php artisan migrate
```

## The problem it solves

A Prism call is a request. It starts, it answers, it's gone.

That's exactly right for generating text, and wrong for an *agent* — something
that runs across many turns, pauses for a human, gets picked up an hour later by
a different queue worker, and is expected to remember what it was doing.

**Resolved, never held.** A Laravel request boots, serves and dies, so a session
can't be an object you keep in memory. Every call rebuilds one from a store,
which is exactly what lets a fresh worker see the same mode, model and
conversation as the request that set them.

## Threads

A thread is the durable conversation. It's an Eloquent model here; the contract
it satisfies lives in Prism core as of 0.113, which is why `withThread()` works
on any provider.

```php
use Prism\Harness\Models\Thread;

$thread = Thread::forParticipant($user, 'support');

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withThread($thread)              // everything said so far
    ->withPrompt('And after that?')    // the turn being taken now
    ->asText();

$thread->record($response->messages);  // the full exchange, tool steps included
```

`record()` takes `$response->messages`, so tool calls and their results are
persisted too — not just the visible text.

> [!TIP]
> `withThread()` is in **core**, not here. Composing a thread into a request is
> a general capability every provider should honour, so it belongs where they
> all live. Harness owns the storage and the lifecycle.

## Sessions

A session pairs a participant with a scope, and carries the mode and model a run
should use.

```php
use Prism\Harness\Facades\PrismHarness;

$session = PrismHarness::for($user)->session('support');

$session->usingMode('plan')->usingModel('claude-sonnet-4-5');

$session->thread();     // the durable conversation for this participant + scope
$session->model();      // what a run should use
```

### Locking

Two workers can hold the same session at once — a queued job finishing a run
while the user sends another message is ordinary, not exotic.

```php
$session->lock(function (Session $session) {
    // whatever must not happen twice
});
```

On timeout `lock()` **throws `SessionLocked` rather than running anyway**, since
running anyway would defeat the only thing it's for. Locks carry an expiry, so a
worker that dies mid-run doesn't hold the session forever.

## Configuring stores

Session state is split across two slots, and they have genuinely different
durability needs:

```php
'stores' => [
    'ephemeral' => 'redis',      // recommended in production
    'durable'   => 'database',
],
```

> [!WARNING]
> **A store that reports itself volatile is refused for durable state, loudly,
> at resolve time.** Redis is the natural home for live session state, but the
> `redis` connection in a typical Laravel app is a *cache* — something is
> entitled to flush it. The package can't tell from the inside whether yours is
> persistent, so `redis` reports volatile by default and pointing the durable
> slot at it throws `UnsafeStateConfiguration`, with both ways out named in the
> message.

If your Redis really is persistent, say so — it's an assertion about your
infrastructure, not a preference:

```php
'drivers' => [
    'redis' => [
        'durable' => true,
    ],
],
```

## Driving a workflow engine

Harness ships an optional bridge to
[fancy-flow](https://github.com/Particle-Academy/fancy-flow-php), so a workflow
graph's `llm_call` and `agent` nodes run on Prism.

```php
use Prism\Harness\Flow\PrismLlmClient;
use Prism\Harness\Flow\HarnessToolInvoker;

$client = new PrismLlmClient(
    defaultProvider: 'anthropic',
    session: $session,          // the run becomes durable
    tools: ['search' => $searchTool],
);
```

Given a session, each node runs against that session's thread — so a workflow
that pauses for approval, checkpoints, and resumes later comes back to an agent
that remembers. Without a session it degrades to a stateless completion, which
is right for a one-shot `llm_call`.

> [!NOTE]
> fancy-flow is a **suggested** dependency, never required. It needs PHP 8.4 and
> Harness supports 8.2, so making it a hard dependency would drop two supported
> PHP versions to gain an integration most applications don't use. Nothing in
> `Flow/` autoloads unless you wire it.

Tools offered to a flow go through an allowlist, and a node asking for one that
isn't on it **raises** rather than returning "unknown tool" as a result:

```php
new HarnessToolInvoker(['search' => $searchTool]);
```

A model handed "unknown tool" as a tool *result* treats it as information and
spends its step budget guessing at an allowlist it can't see — while the
workflow author, who made the actual mistake, sees only a run that produced a
poor answer. Failing names the wrong tool to the person who typed it.

## What isn't built {#what-isn-t-built}

Every row states its status, because an earlier version of this table didn't and
it misled a reader into thinking tool gating was implemented.

| | Status | Shape when it lands |
|---|---|---|
| **Session** | **shipped** | Resolved per request from a store, keyed on participant + scope |
| **Thread** | **shipped** | Eloquent models here; contract defined in Prism 0.113 |
| **Controller** | *planned* | Singleton in the container; config file plus mode classes |
| **Modes** | *planned* | One class per mode, container-resolved so they're testable |
| **Permissions** | *planned* | Gates and Policies — "may this tool run" is an authorization question |
| **Subagents** | *planned* | A Prism Tool wrapping a nested run, with a narrowed toolset |
| **Event bus** | *planned* | Laravel events over Reverb — separate from Prism telemetry |
| **Workspace** | *elsewhere* | Built as [prism-workspace](/companion-packages/workspace) |

`usingMode()` stores a mode name on the session today. What a mode *does* — the
model, tools and system prompt it implies — is the planned part.

## Related

- [Human-in-the-Loop](/core-concepts/human-in-the-loop) — the core primitives
- [Memory](/companion-packages/memory) — when a thread outgrows the context window
- [Workspace](/companion-packages/workspace) — where a session's agent keeps files
