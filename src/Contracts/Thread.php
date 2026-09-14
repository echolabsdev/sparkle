<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

/**
 * A stored conversation Prism can read history from.
 *
 * Prism has no persistence of its own and does not gain any here: this
 * describes a conversation that already exists somewhere — an Eloquent model,
 * a cache entry, an array in a test — and says only that it can produce the
 * messages exchanged so far.
 *
 * Deliberately read-only. Prism never writes to a Thread, so no implementation
 * has to defend against it mutating one. Everything needed to persist a turn is
 * already on the response: `$response->messages` is the full exchange including
 * the tool calls and results from every step, so a caller records what it wants
 * after the fact and Prism keeps no opinion about storage, schema or lifecycle.
 *
 * History composes with a new turn rather than replacing it:
 *
 *     Prism::text()
 *         ->using(Provider::Anthropic, 'claude-sonnet-4-5')
 *         ->withThread($thread)          // everything said so far
 *         ->withPrompt('And after that?') // the turn being taken now
 *         ->asText();
 */
interface Thread
{
    /**
     * The conversation so far, oldest first.
     *
     * Returning an iterable rather than an array lets an implementation page a
     * long history out of storage rather than hydrating all of it up front.
     * Prism still materialises the result — the provider payload needs the
     * whole conversation — so this lowers the cost of reading history, not the
     * peak memory of sending it.
     *
     * Whatever is returned here is replayed to the model as context, and
     * `Message` includes `SystemMessage`. History is therefore as trustworthy
     * as the store it came from, and Prism cannot vouch for it.
     *
     * @return iterable<int, Message>
     */
    public function messages(): iterable;
}
