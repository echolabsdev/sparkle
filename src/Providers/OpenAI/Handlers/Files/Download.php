<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\DownloadFileRequest;

/**
 * @see https://developers.openai.com/api/reference/resources/files/methods/content
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
