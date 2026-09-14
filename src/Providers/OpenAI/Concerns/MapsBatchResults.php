<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\BatchResultStatus;
use Prism\Prism\ValueObjects\Usage;

trait MapsBatchResults
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected static function mapResultItem(array $data): BatchResultItem
    {
        $customId = data_get($data, 'custom_id', '');
        $error = data_get($data, 'error');
        $response = data_get($data, 'response');

        if ($error !== null) {
            $errorCode = data_get($error, 'code', '');

            if ($errorCode === 'batch_expired') {
                return new BatchResultItem(
                    customId: $customId,
                    status: BatchResultStatus::Expired,
                    errorType: $errorCode,
                    errorMessage: data_get($error, 'message'),
                );
            }

            return new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Errored,
                errorType: $errorCode,
                errorMessage: data_get($error, 'message'),
            );
        }

        if ($response === null) {
            return new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Errored,
                errorType: 'unknown',
                errorMessage: 'No response or error in batch result.',
            );
        }

        $statusCode = data_get($response, 'status_code', 0);
        if ($statusCode !== 200) {
            return new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Errored,
                errorType: 'http_error',
                errorMessage: sprintf('HTTP %d: %s', $statusCode, json_encode(data_get($response, 'body.error'))),
            );
        }

        $body = data_get($response, 'body', []);

        return new BatchResultItem(
            customId: $customId,
            status: BatchResultStatus::Succeeded,
            text: self::extractText($body),
            usage: self::extractUsage($body),
            messageId: data_get($body, 'id'),
            model: data_get($body, 'model'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected static function extractText(array $body): string
    {
        $output = data_get($body, 'output', []);

        if (is_array($output)) {
            foreach (array_reverse($output) as $item) {
                if (data_get($item, 'type') === 'message') {
                    $content = data_get($item, 'content', []);
                    foreach ($content as $part) {
                        if (data_get($part, 'type') === 'output_text') {
                            return data_get($part, 'text', '');
                        }
                    }
                }
            }
        }

        $choices = data_get($body, 'choices', []);
        if (! empty($choices)) {
            return data_get($choices, '0.message.content', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected static function extractUsage(array $body): Usage
    {
        return new Usage(
            promptTokens: (int) data_get($body, 'usage.input_tokens',
                data_get($body, 'usage.prompt_tokens', 0)
            ),
            completionTokens: (int) data_get($body, 'usage.output_tokens',
                data_get($body, 'usage.completion_tokens', 0)
            ),
            cacheReadInputTokens: data_get($body, 'usage.input_tokens_details.cached_tokens') !== null
                ? (int) data_get($body, 'usage.input_tokens_details.cached_tokens')
                : null,
            thoughtTokens: data_get($body, 'usage.output_tokens_details.reasoning_tokens') !== null
                ? (int) data_get($body, 'usage.output_tokens_details.reasoning_tokens')
                : null,
        );
    }
}
