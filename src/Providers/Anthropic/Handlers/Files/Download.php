<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\DownloadFileRequest;

/**
 * @see https://platform.claude.com/docs/en/api/beta/files/download
 */
class Download
{
    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(DownloadFileRequest $request): string
    {
        $response = $this->client->get("files/{$request->fileId}/content");

        return $response->body();
    }
}
