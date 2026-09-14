<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\Enums\Provider;

class PrismBatchPayloadSizeExceededException extends PrismException
{
    public function __construct(string|Provider $provider, int $maxPayloadBytes)
    {
        $provider = is_string($provider) ? $provider : $provider->value;

        parent::__construct(
            sprintf(
                '%s request payload size exceeded the maximum of %s bytes.',
                $provider,
                number_format($maxPayloadBytes)
            )
        );
    }

    public static function make(string|Provider $provider, int $maxPayloadBytes): self
    {
        return new self($provider, $maxPayloadBytes);
    }
}
