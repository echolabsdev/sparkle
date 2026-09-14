<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('Azure Error: Empty response');
        }

        if (data_get($data, 'error')) {
            throw PrismException::providerResponseError(
                sprintf('Azure Error: %s', data_get($data, 'error.message', 'Unknown error'))
            );
        }
    }
}
