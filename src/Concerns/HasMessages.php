<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread;

trait HasMessages
{
    /** @var array<int, Message> */
    protected array $messages = [];

    protected ?Thread $thread = null;

    /** @var array<int, Message>|null */
    protected ?array $resolvedThreadMessages = null;

    /**
     * @param  array<int, Message>  $messages
     */
    public function withMessages(array $messages): self
    {
        $this->messages = $messages;

        return $this;
    }

    /**
     * Read the conversation so far from a stored thread.
     *
     * Unlike `withMessages()`, this composes with `withPrompt()`: the thread is
     * the history, the prompt is the turn being taken now. That is the whole
     * point — a conversation you continue rather than one you rebuild by hand
     * on every request.
     */
    public function withThread(Thread $thread): self
    {
        $this->thread = $thread;
        $this->resolvedThreadMessages = null;

        return $this;
    }

    /**
     * The thread's history, resolved once, ahead of anything set explicitly.
     *
     * Memoised on purpose. `messages()` may return a Generator, and a Generator
     * is spent after one pass — without this, building the request a second
     * time would quietly produce a conversation with no history at all.
     *
     * @return array<int, Message>
     */
    protected function threadMessages(): array
    {
        if (! $this->thread instanceof Thread) {
            return [];
        }

        if ($this->resolvedThreadMessages !== null) {
            return $this->resolvedThreadMessages;
        }

        $messages = $this->thread->messages();

        return $this->resolvedThreadMessages = is_array($messages)
            ? array_values($messages)
            : iterator_to_array($messages, false);
    }
}
