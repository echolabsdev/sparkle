<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Files\FileListResult;
use Prism\Prism\Files\ListFilesRequest;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesFileResponse;

/**
 * @see https://developers.openai.com/api/reference/resources/files/methods/list
 */
class ListFiles
{
    use HandlesFileResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(ListFilesRequest $request): FileListResult
    {
        $query = Arr::whereNotNull([
            'limit' => $request->limit,
            'after' => $request->afterId,
            'purpose' => $request->providerOptions('purpose'),
        ]);

        $response = $this->client->get('files', $query);

        $data = $response->json();
        $this->handleResponseErrors($data);

        $files = array_map(
            self::mapFileData(...),
            data_get($data, 'data', [])
        );

        return new FileListResult(
            data: $files,
            hasMore: data_get($data, 'has_more', false),
            firstId: data_get($data, 'first_id'),
            lastId: data_get($data, 'last_id'),
        );
    }
}
