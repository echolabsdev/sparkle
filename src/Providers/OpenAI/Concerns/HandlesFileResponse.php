<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Files\FileData;

trait HandlesFileResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function handleResponseErrors(?array $data): void
    {
        if ($data && data_get($data, 'error')) {
            $message = data_get($data, 'error.message');
            $message = is_array($message) ? implode(', ', $message) : $message;

            throw PrismException::providerResponseError(vsprintf(
                'OpenAI Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    $message,
                ]
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function mapFileData(array $data): FileData
    {
        return new FileData(
            id: data_get($data, 'id', ''),
            filename: data_get($data, 'filename'),
            mimeType: data_get($data, 'mime_type'),
            sizeBytes: data_get($data, 'bytes'),
            createdAt: data_get($data, 'created_at') !== null
                ? date('c', (int) data_get($data, 'created_at'))
                : null,
            purpose: data_get($data, 'purpose'),
            raw: $data,
        );
    }
}
