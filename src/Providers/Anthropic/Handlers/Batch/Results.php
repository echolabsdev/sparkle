<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Batch;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\MapsBatchResults;

/**
 * @see https://platform.claude.com/docs/en/api/beta/messages/batches/results
 */
class Results
{
    use MapsBatchResults;

    /**
     * @see https://www.php.net/manual/en/function.fread.php
     */
    private const STREAM_BUFFER_BYTES = 8192;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * @return BatchResultItem[]
     */
    public function handle(string $batchId): array
    {
        $response = $this->client->withOptions(['stream' => true])->get("messages/batches/{$batchId}/results");

        if ($response->failed()) {
            throw PrismException::providerResponseError(vsprintf(
                'Anthropic Error: failed to retrieve batch results for batch "%s" (HTTP %s)',
                [$batchId, $response->status()]
            ));
        }

        $body = $response->getBody();

        $buffer = '';
        $items = [];

        while (! $body->eof()) {
            $buffer .= $body->read(self::STREAM_BUFFER_BYTES);

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

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

        $buffer = trim($buffer);
        if ($buffer !== '') {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                $items[] = self::mapResultItem($decoded);
            }
        }

        return $items;
    }
}
