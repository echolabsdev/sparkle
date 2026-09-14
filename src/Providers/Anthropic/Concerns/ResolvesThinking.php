<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

trait ResolvesThinking
{
    /**
     * The `thinking` field Anthropic is sent.
     *
     * Two spellings are Prism's and are read here: `['type' => 'adaptive']`, and
     * `['enabled' => true, 'budgetTokens' => n]`, which becomes Anthropic's
     * `['type' => 'enabled', 'budget_tokens' => n]`. A map with `enabled` and no
     * `type` that is not `true` asks for no thinking.
     *
     * Anything else is sent as given, `display` and Anthropic's own
     * `['type' => 'enabled', 'budget_tokens' => n]` included. Those used to be
     * dropped without an error, so a caller who asked for thinking silently got
     * none. The TypeScript and Python ports apply the same rules.
     */
    protected static function resolveThinking(TextRequest|StructuredRequest $request): mixed
    {
        if ($request->reasoningEnabled() === false) {
            return null;
        }

        $thinking = $request->providerOptions('thinking');

        // `false` asks for no thinking. It was ignored before thinking shapes
        // were sent as given, and sending it would turn a config such as
        // env('THINKING', false) into a 400 on every request.
        if ($thinking === false) {
            return null;
        }

        if (! is_array($thinking)) {
            return $thinking;
        }

        if ($thinking === []) {
            return null;
        }

        if (($thinking['type'] ?? null) === 'adaptive') {
            return $thinking;
        }

        if (($thinking['enabled'] ?? null) === true) {
            return [
                'type' => 'enabled',
                'budget_tokens' => is_int($thinking['budgetTokens'] ?? null)
                    ? $thinking['budgetTokens']
                    : config('prism.providers.anthropic.default_thinking_budget', 1024),
            ];
        }

        if (array_key_exists('enabled', $thinking) && ! array_key_exists('type', $thinking)) {
            return null;
        }

        return $thinking;
    }

    /**
     * Whether the request turns thinking on, which rules out forcing a tool.
     */
    protected static function thinkingIsOn(TextRequest|StructuredRequest $request): bool
    {
        $thinking = static::resolveThinking($request);

        return is_array($thinking) && ($thinking['type'] ?? null) !== 'disabled';
    }
}
