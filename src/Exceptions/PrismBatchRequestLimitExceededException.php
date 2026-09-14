<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\Enums\Provider;

class PrismBatchRequestLimitExceededException extends PrismException
{
    public function __construct(string|Provider $provider, int $requestCount, int $maxRequests)
    {
        $provider = is_string($provider) ? $provider : $provider->value;

        parent::__construct(
            sprintf(
                '%s batch limit exceeded: %d requests submitted, maximum is %s.',
                $provider,
                $requestCount,
                number_format($maxRequests)
            )
        );
    }

    public static function make(string|Provider $provider, int $requestCount, int $maxRequests): self
    {
        return new self($provider, $requestCount, $maxRequests);
    }
}
