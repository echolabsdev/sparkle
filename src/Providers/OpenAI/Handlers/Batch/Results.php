<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Closure;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Providers\OpenAI\Concerns\MapsBatchResults;

class Results
{
    use MapsBatchResults;

    /**
     * @param  Closure(string): BatchJob  $retrieveBatch  callback that fetches the batch by ID
     * @param  Closure(string): string  $downloadFile  callback that downloads file content by file ID
     */
    public function __construct(
        protected Closure $retrieveBatch,
        protected Closure $downloadFile,
    ) {}

    /**
     * @return BatchResultItem[]
     */
    public function handle(string $batchId): array
    {
        /**
         * @var BatchJob $batch
         */
        $batch = ($this->retrieveBatch)($batchId);

        if ($batch->outputFileId === null && $batch->errorFileId === null) {
            return [];
        }

        $items = [];

        foreach (array_filter([$batch->outputFileId, $batch->errorFileId]) as $fileId) {
            /** @var string $body */
            $body = ($this->downloadFile)($fileId);

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $items[] = self::mapResultItem($decoded);
            }
        }

        return $items;
    }
}
