<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('Qwen Error: Empty response');
        }

        $code = data_get($data, 'code');
        if ($code !== null && $code !== '') {
            throw PrismException::providerResponseError(
                sprintf('Qwen Error [%s]: %s', $code, data_get($data, 'message', 'Unknown error'))
            );
        }
    }
}
