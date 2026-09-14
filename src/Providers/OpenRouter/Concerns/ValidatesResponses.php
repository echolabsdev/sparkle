<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    use ExtractsErrorDetails;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('OpenRouter Error: Empty response');
        }

        if (data_get($data, 'error')) {
            $this->handleOpenRouterError($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleOpenRouterError(array $data): void
    {
        $errorData = data_get($data, 'error', []);
        $errorData = is_array($errorData) ? $errorData : [];

        $code = data_get($errorData, 'code', 'unknown');
        $metadata = data_get($errorData, 'metadata', []);
        $details = $this->extractErrorDetails($errorData);
        $message = $details['message'];
        $providerLabel = $this->formatProviderLabel($details['providerName']);

        if ($code === 403 && isset($metadata['reasons'])) {
            throw PrismException::providerResponseError(sprintf(
                'OpenRouter Moderation Error%s: %s. Flagged input: %s',
                $providerLabel,
                $message,
                data_get($metadata, 'flagged_input', 'N/A')
            ));
        }

        if ($details['providerName'] !== null) {
            throw PrismException::providerResponseError(sprintf(
                'OpenRouter Provider Error%s: %s',
                $providerLabel,
                $message
            ));
        }

        throw PrismException::providerResponseError(sprintf(
            'OpenRouter Error [%s]: %s',
            $code,
            $message
        ));
    }
}
