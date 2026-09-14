<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Files\FileData;

trait HandlesFileResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function handleResponseErrors(?array $data): void
    {
        if (data_get($data, 'type') === 'error') {
            throw PrismException::providerResponseError(vsprintf(
                'Anthropic Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message'),
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
            sizeBytes: data_get($data, 'size_bytes'),
            createdAt: data_get($data, 'created_at'),
            purpose: null,
            raw: $data,
        );
    }
}
