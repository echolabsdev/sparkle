<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Maps;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Maps Prism messages onto the Agent API's `input`.
 *
 * The Agent API follows the OpenAI Responses schema, where `input` is either a
 * plain string or an array of role/content items. Prism supports multi-turn
 * conversations, so a single-message request sends the string form and anything
 * longer sends the item array — flattening a conversation into one string would
 * lose which side said what, and a model that cannot tell its own previous
 * answers from the user's questions answers worse.
 *
 * System messages are deliberately NOT included here. They travel as
 * `instructions`, which is a separate field with different semantics — see
 * HandlesHttpRequests::instructions().
 */
class InputMapper
{
    /**
     * @param  array<int, Message>  $messages
     * @return string|array<int, array<string, string>>
     */
    public static function map(array $messages): string|array
    {
        $items = [];

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                continue;
            }

            if ($message instanceof UserMessage) {
                $items[] = ['role' => 'user', 'content' => $message->text()];

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $items[] = ['role' => 'assistant', 'content' => $message->content];
            }

            // Tool result messages have no Agent API equivalent: its tools run
            // server-side, so there is no client-side result to hand back.
            // Dropping them here is correct rather than lossy.
        }

        if ($items === []) {
            return '';
        }

        // One user turn is the overwhelmingly common case, and the string form
        // is what every Perplexity example shows — so use it where it applies
        // and keep the array form for conversations that need it.
        if (count($items) === 1 && $items[0]['role'] === 'user') {
            return $items[0]['content'];
        }

        return $items;
    }
}
