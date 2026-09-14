<?php

declare(strict_types=1);

namespace Prism\Prism\Fim;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use HasProviderOptions;

    protected string $prompt = '';

    protected ?string $suffix = null;

    protected ?int $maxTokens = null;

    protected int|float|null $temperature = null;

    protected int|float|null $topP = null;

    /** @var array<string> */
    protected array $stop = [];

    public function withPrompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function withSuffix(?string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function withTemperature(int|float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function withTopP(int|float $topP): self
    {
        $this->topP = $topP;

        return $this;
    }

    /**
     * @param  string|array<string>  $stop
     */
    public function withStop(string|array $stop): self
    {
        $this->stop = is_string($stop) ? [$stop] : $stop;

        return $this;
    }

    public function asText(): Response
    {
        $request = $this->toRequest();

        try {
            return $this->provider->fim($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @deprecated Use `asText` instead.
     */
    public function generate(): Response
    {
        return $this->asText();
    }

    public function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            prompt: $this->prompt,
            suffix: $this->suffix,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            stop: $this->stop,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
        );
    }
}
