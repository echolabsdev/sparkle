<?php

namespace Prism\Prism\Providers\Perplexity;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Providers\Perplexity\Handlers\Stream;
use Prism\Prism\Providers\Perplexity\Handlers\Structured;
use Prism\Prism\Providers\Perplexity\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use SensitiveParameter;

/**
 * @link https://docs.perplexity.ai/docs/agent-api/quickstart
 * @link https://docs.perplexity.ai/guides/image-attachments
 * @link https://docs.perplexity.ai/guides/file-attachments
 */
class Perplexity extends Provider
{
    use InitializesClient;

    public function __construct(
        #[SensitiveParameter]
        protected string $apiKey,
        public readonly string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $textHandler = new Text($this->client($request->clientOptions(), $request->clientRetry()));

        return $textHandler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $textHandler = new Structured($this->client($request->clientOptions(), $request->clientRetry()));

        return $textHandler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->acceptJson()
            ->baseUrl($baseUrl ?? $this->url);
    }
}
