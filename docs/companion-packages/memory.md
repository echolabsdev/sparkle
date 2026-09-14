# Prism Memory

**Persistent context and semantic recall** — vector storage, `remember`/`recall`,
and token-budget-aware retrieval.

> [!WARNING]
> **Status: the first slice works end to end**, and it isn't on Packagist yet —
> install from the repository. A vector store, a `remember`/`recall` pair, one
> storage driver, queued batch embedding, and forgetting.

An agent's useful context outgrows its context window. A thread replayed whole
is the crudest possible memory: it grows without bound, costs tokens linearly,
and eventually stops fitting. This stores what was said and retrieves **only the
parts that matter now**.

```php
$memory = PrismMemory::for($user, scope: 'support');

$memory->remember($response->messages);

$relevant = $memory->recall('billing address', budget: 1500);

Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withSystemPrompt($instructions)
    ->withSystemPrompt($relevant->asContext())
    ->withPrompt($question)
    ->asText();
```

## Handing a recollection to Prism

> [!WARNING]
> Use `withSystemPrompt($relevant->asContext())`. **Not**
> `withMessages($relevant->asMessages())->withPrompt(...)`.
>
> Prism refuses a request carrying both an explicit message list and a prompt —
> deliberately, because there's no defensible order to merge them in. That makes
> the second form a trap of an unusually nasty shape: it works in development
> against an empty store, and throws the first time recall actually returns
> something.

`asMessages()` returns **one** system message, not one per memory. Replaying
recollections as prior user and assistant turns tells the model a fragment was
the last thing said — untrue about the conversation, and it invites the model to
answer the recalled question instead of the one being asked.

## recall()

```php
$relevant = $memory->recall(
    'billing address',
    budget: 1500,                                    // fill a token budget, not a fixed k
    weighting: new Weighting(relevance: 0.7, recency: 0.3),
    filter: ['role' => 'user'],
);
```

**Token budgets, not top-k.** You know your context window; a memory layer that
returns five passages whether or not they fit has left the hard part to you.
`budget` fills up to an estimated token count, and doesn't stop at the first
memory that doesn't fit — that would hand back one where the budget had room for
six.

**Relevance and recency are separate axes.** Pure similarity returns the most
*similar* memory, which isn't the most *useful* one: "what is my billing
address" matches every previous time the address came up, including the one
since superseded.

Each result keeps its score **broken into parts** — `similarity`, `recency`,
`score`. One blended number tells you a memory ranked third and nothing about
whether it was a close match that was old or a weak match that was fresh, and
those call for opposite fixes.

## Ordering is a cache property

**A `Recollection` is chronological, not score-ordered.** That looks like a
missed opportunity to rank, and it's load-bearing.

Anthropic and OpenAI both price a cached **prefix** at a fraction of a fresh
one, and recalled memories sit inside it. Score order is unstable by nature: two
memories a thousandth of a cosine apart swap places when a third arrives, when
the query is reworded, when a recency weight ticks over. Same set, different
order, different prefix, missed cache — and your usage numbers then say the
request got *more* expensive without saying why.

A memory's position in time never changes, so adding one appends rather than
reshuffles — the one mutation a prefix cache survives.

```php
Prism::text()
    ->withSystemPrompt($instructions)                  // stable
    ->withSystemPrompt($relevant->asContext())         // changes as memory grows
    ->withPrompt($question)                            // changes every turn
```

Stable content first, volatile last, on every provider. `ranked()` gives you
score order, for looking at rather than for building a prompt.

> [!TIP]
> `$relevant->digest()` fingerprints the exact bytes a recollection contributes.
> Log it, and a change in your provider's cache-read token count stops being a
> mystery: either the digest moved and the miss is explained, or it didn't and
> the cause is elsewhere.

## remember()

Takes `$response->messages` — the whole exchange including tool steps — or a
string, or a single message.

**It doesn't embed inside the request.** A record is written immediately and
embedded afterwards through your queue, because embedding is a provider round
trip and doing it inline means every turn waits on the previous turn's
bookkeeping.

So **a memory isn't recallable until its vector arrives.** `$memory->pending()`
watches that; `$memory->synchronously()` opts out, visibly, at the call site.
Laravel's default connection runs jobs inline, so a fresh install behaves as
though embedding were synchronous.

A turn producing six memories costs **one** provider call, not six. Writing is
idempotent — the record id is a digest of content and role, so a conversation
re-recorded after a retry doesn't double the store.

## Forgetting

```php
$memory->forget($ids);              // specific memories
$memory->forget();                  // this owner, this scope, all of it
$memory->forgetBefore($date);       // everything that HAPPENED before $date
```

**A hard delete, not a flag.** "Forget this" is a request a person is entitled
to make about what a system knows of them, and a soft delete answers it with a
row that still exists. `forget()` returns how many rows went, because that's an
assertion someone may later have to evidence.

`forgetBefore()` cuts on when the remembered thing *occurred*, not when the row
was written — so a conversation backfilled last week from two years ago is
forgotten by the age of the conversation.

Retention is off by default, and expiry is enforced **on read**, so a memory
past its window is never recalled just because nothing pruned it.

## Related

- [Harness](/companion-packages/harness) — owns the threads this reads
