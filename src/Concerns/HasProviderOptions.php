<?php

namespace Prism\Prism\Concerns;

trait HasProviderOptions
{
    /** @var array<string, mixed> */
    protected array $providerOptions = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options = []): self
    {
        $this->providerOptions = $options;

        return $this;
    }

    public function providerOptions(?string $valuePath = null, mixed $default = null): mixed
    {
        if ($valuePath === null) {
            return $this->providerOptions;
        }

        return data_get($this->providerOptions, $valuePath, $default);
    }
}
