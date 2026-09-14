<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\GetFileMetadataRequest;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesFileResponse;

/**
 * @see https://platform.claude.com/docs/en/api/beta/files/retrieve_metadata
 */
class GetMetadata
{
    use HandlesFileResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(GetFileMetadataRequest $request): FileData
    {
        $response = $this->client->get("files/{$request->fileId}");

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapFileData($data);
    }
}
