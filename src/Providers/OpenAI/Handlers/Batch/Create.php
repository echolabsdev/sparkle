<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Files\FileData;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsRequestBody;
use Prism\Prism\Providers\OpenAI\Concerns\HandlesBatchResponse;

/**
 * It handles the creation of a batch in OpenAI. If the batch requests are provided as an array of items, it will build a JSONL file and upload it to the OpenAI API.
 * If the batch requests are provided as an input file ID, it will use the input file ID directly.
 *
 * @see https://developers.openai.com/api/docs/guides/batch
 * @see https://developers.openai.com/api/reference/resources/batches/methods/create
 */
class Create
{
    use BuildsRequestBody;
    use HandlesBatchResponse;

    /**
     * @param  Closure(string $content, string $filename): FileData  $uploadFile  callback that uploads JSONL content and returns the resulting FileData
     */
    public function __construct(
        protected PendingRequest $client,
        protected Closure $uploadFile,
    ) {}

    public function handle(BatchRequest $request): BatchJob
    {
        if ($request->inputFileId !== null && $request->items !== null) {
            throw new PrismException('OpenAI batch requires either "inputFileId" or "items", not both.');
        }

        if ($request->inputFileId === null && $request->items === null) {
            throw new PrismException('OpenAI batch requires either "inputFileId" or "items".');
        }

        $inputFileId = $request->inputFileId ?? $this->buildAndUploadFile($request->items ?? []);

        $response = $this->client->post('batches', [
            'input_file_id' => $inputFileId,
            'endpoint' => '/v1/responses',
            'completion_window' => $request->providerOptions('completion_window') ?? '24h',
        ]);

        $data = $response->json();
        $this->handleResponseErrors($data);

        return self::mapBatchJob($data);
    }

    /**
     * @param  BatchRequestItem[]  $items
     */
    protected function buildAndUploadFile(array $items): string
    {
        $jsonl = implode("\n", array_map(
            fn (BatchRequestItem $item): string => (string) json_encode([
                'custom_id' => $item->customId,
                'method' => 'POST',
                'url' => '/v1/responses',
                'body' => $this->buildRequestBody($item->request),
            ]),
            $items
        ));

        $fileName = 'prism-batch-'.Str::uuid()->toString().'.jsonl';

        $file = ($this->uploadFile)($jsonl, $fileName);

        return $file->id;
    }
}
