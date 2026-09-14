<?php

declare(strict_types=1);

namespace Prism\Prism\Fim;

use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\PrismRequest;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  array<string>  $stop
     * @param  array<string, mixed>  $clientOptions
     * @param  array<mixed>  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        protected string $model,
        protected string $providerKey,
        protected string $prompt,
        protected ?string $suffix,
        protected ?int $maxTokens,
        protected int|float|null $temperature,
        protected int|float|null $topP,
        protected array $stop,
        protected array $clientOptions,
        protected array $clientRetry,
        array $providerOptions = [],
    ) {
        $this->providerOptions = $providerOptions;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return $this->providerKey;
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    public function suffix(): ?string
    {
        return $this->suffix;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): int|float|null
    {
        return $this->temperature;
    }

    public function topP(): int|float|null
    {
        return $this->topP;
    }

    /**
     * @return array<string>
     */
    public function stop(): array
    {
        return $this->stop;
    }

    /**
     * @return array<string, mixed>
     */
    public function clientOptions(): array
    {
        return $this->clientOptions;
    }

    /**
     * @return array<mixed>
     */
    public function clientRetry(): array
    {
        return $this->clientRetry;
    }
}
