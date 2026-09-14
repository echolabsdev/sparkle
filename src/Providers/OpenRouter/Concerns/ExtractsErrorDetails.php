<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use JsonException;

trait ExtractsErrorDetails
{
    /**
     * Extracts the most informative error message and provider name out of an
     * OpenRouter error payload. OpenRouter wraps upstream provider errors in
     * `error.metadata.raw`, and the shape of that raw payload varies by
     * provider (Azure/OpenAI nest under `error.message`, Bedrock and others
     * put `message` at the top level, and some return a plain string).
     *
     * @param  array<string, mixed>  $errorData  The "error" key from the response.
     * @return array{message: string, providerName: ?string}
     */
    protected function extractErrorDetails(array $errorData): array
    {
        $topMessage = data_get($errorData, 'message');
        $metadata = data_get($errorData, 'metadata', []);
        $providerName = data_get($metadata, 'provider_name');
        $raw = data_get($metadata, 'raw');

        $rawMessage = $this->extractMessageFromRaw($raw);

        $message = $rawMessage
            ?? (is_string($topMessage) && $topMessage !== '' ? $topMessage : 'Unknown error');

        return [
            'message' => $message,
            'providerName' => is_string($providerName) && $providerName !== '' ? $providerName : null,
        ];
    }

    protected function extractMessageFromRaw(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw;
        }

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        if (! is_array($decoded)) {
            return $raw;
        }

        foreach (['error.message', 'message', 'Message', 'error_message', 'detail'] as $path) {
            $candidate = data_get($decoded, $path);

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $error = data_get($decoded, 'error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return null;
    }

    protected function formatProviderLabel(?string $providerName): string
    {
        return $providerName !== null ? sprintf(' (%s)', $providerName) : '';
    }
}
