<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\UploadFileRequest;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesFileResponse;

/**
 * @see https://developers.openai.com/api/reference/resources/files/methods/create
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
            ->post('files', [
                'purpose' => $request->providerOptions('purpose'),
            ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapFileData($data);
    }
}
