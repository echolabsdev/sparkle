<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Maps;

/**
 * Translates the retired Sonar model slugs onto Agent API presets.
 *
 * Perplexity retires the Sonar `/chat/completions` endpoints on 2026-09-27 and
 * replaces model slugs with presets. Translating here rather than asking every
 * caller to rewrite their config is the point: `using('perplexity', 'sonar-pro')`
 * keeps working across the cut.
 *
 * ---
 *
 * A NOTE ON THE MAPPING, because it decides what you are billed.
 *
 * Perplexity publishes two mappings that disagree, and only on the expensive
 * rows.
 *
 * Their migration overview offers "starting points" — `sonar-reasoning-pro` to
 * `medium`, `sonar-deep-research` to `high`. That ladder tracks product-name
 * seniority: bigger name, bigger tier.
 *
 * Their preset documentation shows the presets were RENAMED:
 *
 *     fast-search → fast
 *     pro-search  → low
 *     deep-research → medium
 *     advanced-deep-research → high
 *     ultra → xhigh
 *
 * The preset that was literally called *deep-research* is now called *medium*.
 * So the behavioural equivalent of `sonar-deep-research` is `medium`, and
 * `high` is the tier above it — a costlier upgrade rather than a like-for-like
 * replacement.
 *
 * This class defaults to the equivalence reading. Guessing low is recoverable:
 * a caller who wants more depth sets a preset and gets it. Guessing high is not
 * visible at all — it returns a perfectly plausible answer and bills more,
 * every call, until somebody reads an invoice.
 *
 * Override per request if you disagree:
 *
 *     ->withProviderOptions(['preset' => 'high'])
 */
class PresetMap
{
    /**
     * The presets the Agent API accepts.
     *
     * @var array<int, string>
     */
    public const PRESETS = ['fast', 'low', 'medium', 'high', 'xhigh', 'wide-research'];

    /**
     * Retired Sonar slug to its behavioural equivalent.
     *
     * @var array<string, string>
     */
    public const SLUGS = [
        'sonar' => 'fast',
        'sonar-pro' => 'low',
        // The slug itself is retired with no direct successor; `low` is the
        // nearest reasoning-capable tier.
        'sonar-reasoning' => 'low',
        'sonar-reasoning-pro' => 'low',
        'sonar-deep-research' => 'medium',
    ];

    /**
     * Is this string a preset the Agent API already understands?
     */
    public static function isPreset(string $model): bool
    {
        return in_array($model, self::PRESETS, true);
    }

    /**
     * Is this a retired Sonar slug we translate on the caller's behalf?
     */
    public static function isRetiredSlug(string $model): bool
    {
        return array_key_exists($model, self::SLUGS);
    }

    /**
     * The preset for a model string, or null when it is a real model id.
     *
     * A value this does not recognise — `openai/gpt-5.6-sol`, say — is passed
     * through as a `model` instead, because the Agent API accepts either and
     * guessing a preset for an unknown string would silently change which
     * model served the request.
     */
    public static function presetFor(string $model): ?string
    {
        if (self::isPreset($model)) {
            return $model;
        }

        return self::SLUGS[$model] ?? null;
    }
}
