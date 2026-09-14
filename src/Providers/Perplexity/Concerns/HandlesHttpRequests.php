<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Perplexity\Maps\InputMapper;
use Prism\Prism\Providers\Perplexity\Maps\PresetMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

trait HandlesHttpRequests
{
    protected function sendRequest(PendingRequest $client, TextRequest|StructuredRequest $request, bool $stream = false): Response
    {
        return $client->post(
            '/v1/agent',
            $this->buildHttpRequestPayload($request, $stream)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    protected function buildHttpRequestPayload(TextRequest|StructuredRequest $request, bool $stream = false): array
    {
        $this->assertToolsAreReachable($request);
        $this->assertSonarOnlyOptionsAreNotSet($request);

        $payload = [
            'input' => InputMapper::map($request->messages()),
            'stream' => $stream,
        ];

        // `preset` and `model` are mutually exclusive, and NOT because the API
        // rejects the pair — it accepts both and lets the explicit model win,
        // so leaving `model` populated silently ignores the preset. Sending
        // exactly one is what makes the translation actually take effect.
        $payload += $this->modelOrPreset($request);

        return array_merge($payload, Arr::whereNotNull([
            'instructions' => $this->instructions($request),
            'response_format' => $this->responseFormat($request),
            'max_output_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $this->tools($request),
            'background' => $request->providerOptions('background'),
            'max_steps' => $request->providerOptions('max_steps'),
            'models' => $request->providerOptions('models'),
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'store' => $request->providerOptions('store'),
            'reasoning' => $this->reasoning($request),
            'skills' => $request->providerOptions('skills'),
            'metadata' => $request->providerOptions('metadata'),
            'language_preference' => $request->providerOptions('language_preference'),
        ]));
    }

    /**
     * Sonar's top-level search filters, moved onto the `web_search` tool.
     *
     * The Agent API decodes its body STRICTLY: an unknown field is a 400, not
     * an ignored key. So every one of these sent at the top level was a request
     * that failed outright — and it only surfaced in production because
     * `Arr::whereNotNull` drops what nobody set, so the field appears solely on
     * the runs where a caller supplied it. Reported as prism#31, by a consumer
     * whose model chose to narrow a search on a fraction of its runs.
     *
     * TRANSLATED rather than dropped. Silently discarding a domain allowlist is
     * the worse failure of the two: the request succeeds, the search is
     * quietly broader than the caller asked for, and the answer cites sources
     * they deliberately excluded. That is the same mistake `withTools()` used to
     * make above, and it is why that one now throws.
     *
     * An explicit `filters` already on the tool WINS, on the same principle as
     * `preset` over `model` below: the specific spelling beats the translated
     * one, so a caller who has migrated is never second-guessed.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function tools(TextRequest|StructuredRequest $request): ?array
    {
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $request->providerOptions('tools');

        $filters = Arr::whereNotNull([
            'search_domain_filter' => $request->providerOptions('search_domain_filter'),
            'search_recency_filter' => $request->providerOptions('search_recency_filter'),
            'search_after_date_filter' => $request->providerOptions('search_after_date_filter'),
            'search_before_date_filter' => $request->providerOptions('search_before_date_filter'),
            'last_updated_after_filter' => $request->providerOptions('last_updated_after_filter'),
            'last_updated_before_filter' => $request->providerOptions('last_updated_before_filter'),
        ]);

        if ($filters === []) {
            return $tools;
        }

        // A filter with no web_search tool to carry it would otherwise vanish.
        // Declaring the tool is what the migration guide says to do, and it is
        // what the caller plainly meant by setting a search filter at all.
        if ($tools === null || $tools === []) {
            return [['type' => 'web_search', 'filters' => $filters]];
        }

        $found = false;

        foreach ($tools as $index => $tool) {
            if (($tool['type'] ?? null) !== 'web_search') {
                continue;
            }

            $found = true;
            $existing = $tool['filters'] ?? [];

            // A scalar here used to reach `array_merge` and surface as a raw
            // TypeError — an unhandled 500 in the calling app rather than a
            // provider option it can catch. Refused by name for the same reason
            // the three below are: `tools` can be model-supplied, and a bad
            // value has to say which option was bad.
            if (! is_array($existing)) {
                throw new PrismException(sprintf(
                    'Perplexity provider option [tools][%s][filters] must be an array of search '
                    .'filters, %s given.',
                    $index,
                    get_debug_type($existing),
                ));
            }

            $tools[$index]['filters'] = array_merge($filters, $existing);
        }

        return $found ? $tools : [...$tools, ['type' => 'web_search', 'filters' => $filters]];
    }

    /**
     * `reasoning_effort` is `reasoning.effort` on the Agent API.
     *
     * Another strict-decode 400 in the old allowlist, and another one worth
     * translating rather than dropping: effort is what a caller pays for.
     * An explicit `reasoning` object wins.
     *
     * @return array<string, mixed>|null
     */
    protected function reasoning(TextRequest|StructuredRequest $request): ?array
    {
        /** @var array<string, mixed>|null $reasoning */
        $reasoning = $request->providerOptions('reasoning');

        if ($reasoning !== null) {
            return $reasoning;
        }

        $effort = $request->providerOptions('reasoning_effort');

        return $effort === null ? null : ['effort' => $effort];
    }

    /**
     * Refuse the three Sonar options the Agent API has no answer for.
     *
     * `search_mode`, `return_images` and `return_related_questions` are not
     * renamed and not nested — Perplexity's own migration guide records them as
     * having no equivalent. Forwarding them was a 400; dropping them silently
     * would be worse, because the caller asked for something and got a
     * successful response that does not contain it.
     *
     * So this refuses, names the option, and says what to do instead — the same
     * shape as `assertToolsAreReachable`, for the same reason.
     *
     * The two booleans are refused only when they are TRUE. `return_images:
     * false` asks for exactly what the Agent API already does, so the caller's
     * intent is met and an exception would reject a request that is in effect
     * correct. That is not a hypothetical: all three are commonly declared to a
     * model as tool parameters, so a model supplying the no-op value would take
     * down a run it did nothing wrong in — the same model-triggerable path that
     * made prism#31 a production incident rather than a config bug.
     *
     * `search_mode` NAMES a mode rather than toggling one. It has no value
     * meaning "do nothing", so its presence is the ask and any value is refused.
     *
     * A falsy value that is not `false` — `0`, `''` — is still refused. These
     * are declared booleans, `false` is the only no-op spelling valid for the
     * type, and quietly accepting the others would be the silent-drop failure
     * this whole method exists to avoid.
     */
    protected function assertSonarOnlyOptionsAreNotSet(TextRequest|StructuredRequest $request): void
    {
        $unsupported = [
            'search_mode' => ['boolean' => false, 'advice' => "the Agent API has no search_mode. Use a preset instead: 'fast' maps to ->withProviderOptions(['preset' => 'fast']) and 'pro' to 'low'."],
            'return_images' => ['boolean' => true, 'advice' => 'the Agent API does not return images, and there is no equivalent option.'],
            'return_related_questions' => ['boolean' => true, 'advice' => 'the Agent API has no related-questions flag. Ask for them in the prompt, or through a structured output schema.'],
        ];

        foreach ($unsupported as $option => ['boolean' => $isBoolean, 'advice' => $advice]) {
            $value = $request->providerOptions($option);

            // Never set at all.
            if ($value === null) {
                continue;
            }

            // Set to the value that asks for what the Agent API already does.
            if ($isBoolean && $value === false) {
                continue;
            }

            throw new PrismException(sprintf(
                'Perplexity provider option [%s] is a Sonar chat/completions option: %s '
                .'Sending it produced an HTTP 400, because the Agent API rejects unknown fields '
                .'rather than ignoring them.',
                $option,
                $advice,
            ));
        }
    }

    /**
     * Refuse `withTools()` rather than dropping it on the floor.
     *
     * Perplexity's tools run server-side: you declare which of ITS tools a run
     * may use — web_search, fetch_url, sandbox, mcp — and it executes them
     * itself. There is no round trip that would let it invoke a PHP closure, so
     * a Prism Tool cannot be handed over.
     *
     * The provider has never supported them, and before this check it accepted
     * `withTools()` and quietly ignored it: the run came back with zero steps
     * and zero tool calls, and an answer that simply never used the tool. That
     * is worse than an error, because it reads as the model deciding not to
     * call it. Confirmed against a live key before adding this.
     *
     * Perplexity's own tools are reachable through provider options:
     *
     *     ->withProviderOptions(['tools' => [['type' => 'web_search']]])
     */
    protected function assertToolsAreReachable(TextRequest|StructuredRequest $request): void
    {
        if ($request->tools() === []) {
            return;
        }

        throw new PrismException(
            'Perplexity cannot execute Prism tools: its tools run server-side, so there is no '
            ."callback for a PHP closure. Pass Perplexity's own tools with "
            ."->withProviderOptions(['tools' => [['type' => 'web_search']]]) instead of ->withTools()."
        );
    }

    /**
     * Exactly one of `preset` or `model`, never both.
     *
     * An explicit `preset` provider option always wins — that is the escape
     * hatch for anyone who disagrees with the slug translation and wants to pay
     * for a different tier.
     *
     * @return array<string, string>
     */
    protected function modelOrPreset(TextRequest|StructuredRequest $request): array
    {
        $explicit = $request->providerOptions('preset');

        if (is_string($explicit) && $explicit !== '') {
            return ['preset' => $explicit];
        }

        $model = $request->model();
        $preset = PresetMap::presetFor($model);

        // An unrecognised string is a real model id (`openai/gpt-5.6-sol`), not
        // a preset we should guess at.
        return $preset === null
            ? ['model' => $model]
            : ['preset' => $preset];
    }

    /**
     * The system prompt, as the Agent API's `instructions`.
     *
     * Worth knowing before you set one: `instructions` REPLACES the preset's
     * own system prompt rather than being appended to it. A preset is a model
     * plus a prompt tuned against real workloads, and passing instructions
     * discards that half of it. Nothing errors; the answers just change.
     *
     * Prism only sends this when the caller actually set a system prompt, so
     * the preset keeps its own prompt by default.
     */
    protected function instructions(TextRequest|StructuredRequest $request): ?string
    {
        $prompts = array_map(
            fn (SystemMessage $message): string => $message->content,
            $request->systemPrompts(),
        );

        $joined = trim(implode("\n\n", array_filter($prompts, fn (string $p): bool => trim($p) !== '')));

        return $joined === '' ? null : $joined;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function responseFormat(TextRequest|StructuredRequest $request): ?array
    {
        if (! $request instanceof StructuredRequest) {
            return null;
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'schema' => $request->schema()->toArray(),
            ],
        ];
    }
}
