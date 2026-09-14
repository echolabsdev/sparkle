<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

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
        $resultType = data_get($data, 'result.type', '');

        return match ($resultType) {
            'succeeded' => new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Succeeded,
                text: self::extractText(data_get($data, 'result.message', [])),
                usage: self::extractUsage(data_get($data, 'result.message.usage', [])),
                messageId: data_get($data, 'result.message.id'),
                model: data_get($data, 'result.message.model'),
            ),
            'errored' => new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Errored,
                errorType: data_get($data, 'result.error.type'),
                errorMessage: data_get($data, 'result.error.message'),
            ),
            'canceled' => new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Canceled,
            ),
            'expired' => new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Expired,
            ),
            default => new BatchResultItem(
                customId: $customId,
                status: BatchResultStatus::Errored,
                errorType: 'unknown',
                errorMessage: "Unknown result type: {$resultType}",
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected static function extractText(array $message): string
    {
        $content = data_get($message, 'content', []);

        $texts = [];
        foreach ($content as $block) {
            if (data_get($block, 'type') === 'text') {
                $texts[] = data_get($block, 'text', '');
            }
        }

        return implode('', $texts);
    }

    /**
     * @param  array<string, mixed>  $usageData
     */
    protected static function extractUsage(array $usageData): Usage
    {
        return new Usage(
            promptTokens: (int) data_get($usageData, 'input_tokens', 0),
            completionTokens: (int) data_get($usageData, 'output_tokens', 0),
            cacheWriteInputTokens: data_get($usageData, 'cache_creation_input_tokens') !== null
                ? (int) data_get($usageData, 'cache_creation_input_tokens')
                : null,
            cacheReadInputTokens: data_get($usageData, 'cache_read_input_tokens') !== null
                ? (int) data_get($usageData, 'cache_read_input_tokens')
                : null,
            thoughtTokens: data_get($usageData, 'output_tokens_details.thinking_tokens') !== null
                ? (int) data_get($usageData, 'output_tokens_details.thinking_tokens')
                : null,
        );
    }
}
