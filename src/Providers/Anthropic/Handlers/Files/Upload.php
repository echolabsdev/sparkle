<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\UploadFileRequest;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesFileResponse;

/**
 * @see https://platform.claude.com/docs/en/api/beta/files/upload
 */
class Upload
{
    use HandlesFileResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(UploadFileRequest $request): FileData
    {
        $response = $this->client
            ->asMultipart()
            ->attach('file', $request->content, $request->filename)
            ->post('files');

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapFileData($data);
    }
}
