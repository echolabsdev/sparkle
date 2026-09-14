<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresGeneration;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStructuredOutput;
use Prism\Prism\Concerns\ConfiguresTools;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasReasoning;
use Prism\Prism\Concerns\HasSchema;
use Prism\Prism\Concerns\HasTelemetryMetadata;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresGeneration;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresStructuredOutput;
    use ConfiguresTools;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasProviderTools;
    use HasReasoning;
    use HasSchema;
    use HasTelemetryMetadata;
    use HasTools;

    /**
     * @deprecated Use `asStructured` instead.
     */
    public function generate(): Response
    {
        return $this->asStructured();
    }

    public function asStructured(): Response
    {
        $request = $this->toRequest();

        $context = Telemetry::start(TelemetryOperation::Structured, $this->providerKey(), $request->model(), $request, $this->telemetryUserId, $this->telemetrySessionId);

        try {
            $response = $this->provider->structured($request);

            Telemetry::completed($context, $response, $response->finishReason, $response->usage);

            return $response;
        } catch (RequestException $e) {
            Telemetry::failed($context, $e);

            $this->provider->handleRequestException($request->model(), $e);
        } finally {
            Telemetry::end($context);
        }
    }

    public function toRequest(): Request
    {
        // Neither truthiness nor filled(). "" and "0" are the only strings PHP
        // counts as falsy, so gating this on truthiness let a caller who set
        // BOTH messages and a "0" prompt past the refusal — and then dropped
        // the prompt below. A successful call that answered a different
        // question than the one asked, with nothing to indicate it.
        //
        // filled() fixes that input and breaks another: it trims, so a prompt
        // of "  " would start being dropped in exactly the same silent way.
        // This test differs from the original on one input — the one at issue.
        if ($this->messages !== [] && $this->prompt !== null && $this->prompt !== '') {
            throw PrismException::promptOrMessages();
        }

        $messages = [...$this->threadMessages(), ...$this->messages];

        if ($this->prompt !== null && $this->prompt !== '') {
            $messages[] = new UserMessage($this->prompt, $this->additionalContent);
        }

        if (! $this->schema instanceof Schema) {
            throw new PrismException('A schema is required for structured output');
        }

        return new Request(
            systemPrompts: $this->systemPrompts,
            model: $this->model,
            providerKey: $this->providerKey(),
            prompt: $this->prompt,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            topK: $this->topK,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            schema: $this->schema,
            mode: $this->structuredMode,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
            maxSteps: $this->maxSteps,
            providerOptions: $this->providerOptions,
            providerTools: $this->providerTools,
            reasoningEnabled: $this->reasoningEnabled,
        );
    }
}
