<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies;

use Illuminate\Http\Client\Response as HttpResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Response as PrismResponse;

class NativeOutputFormatStructuredStrategy extends AnthropicStructuredStrategy
{
    public function appendMessages(): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutatePayload(array $payload): array
    {
        $schemaArray = $this->request->schema()->toArray();

        $payload['output_config'] = array_merge($payload['output_config'] ?? [], [
            'format' => [
                'type' => 'json_schema',
                'schema' => $schemaArray,
            ],
        ]);

        return $payload;
    }

    public function mutateResponse(HttpResponse $httpResponse, PrismResponse $prismResponse): PrismResponse
    {
        $structured = json_decode($prismResponse->text, associative: true) ?? [];

        return new PrismResponse(
            steps: $prismResponse->steps,
            text: '',
            structured: $structured,
            finishReason: $prismResponse->finishReason,
            usage: $prismResponse->usage,
            meta: $prismResponse->meta,
            additionalContent: $prismResponse->additionalContent
        );
    }

    protected function checkStrategySupport(): void
    {
        if ($this->request->providerOptions('citations') === true) {
            throw new PrismException(
                'Citations are not supported with native output_format. Please disable citations or use a different structured output strategy.'
            );
        }
    }
}
