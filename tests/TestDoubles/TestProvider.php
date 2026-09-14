<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Generator;
use Prism\Prism\Audio\ProviderIdResponse;
use Prism\Prism\Audio\SpeechToTextAsyncRequest;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request as ImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderResponse;
use Prism\Prism\ValueObjects\Usage;

class TestProvider extends Provider
{
    public StructuredRequest|TextRequest|EmbeddingRequest|ImageRequest|SpeechToTextRequest|SpeechToTextAsyncRequest $request;

    /** @var array<string, mixed> */
    public array $clientOptions;

    /** @var array<mixed> */
    public array $clientRetry;

    /** @var array<int, StructuredResponse|TextResponse|EmbeddingResponse|ImageResponse|AudioTextResponse|ProviderIdResponse> */
    public array $responses = [];

    public $callCount = 0;

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new TextResponse(
            steps: collect([]),
            text: "I'm nyx!",
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 10),
            meta: new Meta('123', 'claude-3-5-sonnet-20240620'),
            messages: collect([]),
        );
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new StructuredResponse(
            steps: collect([]),
            text: json_encode([]),
            structured: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(10, 10),
            meta: new Meta('123', 'claude-3-5-sonnet-20240620')
        );
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new EmbeddingResponse(
            embeddings: [],
            usage: new EmbeddingsUsage(10),
            meta: new Meta(
                id: '123',
                model: 'your-model',
            )
        );
    }

    #[\Override]
    public function images(ImageRequest $request): ImageResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new ImageResponse(
            images: [
                new GeneratedImage(
                    url: 'https://example.com/test-image.png',
                    revisedPrompt: null,
                ),
            ],
            usage: new Usage(10, 10),
            meta: new Meta('123', 'dall-e-3'),
        );
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    #[\Override]
    public function speechToTextProviderId(SpeechToTextRequest $request): ProviderIdResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new ProviderIdResponse('provider-id');
    }

    #[\Override]
    public function speechToTextAsync(SpeechToTextAsyncRequest $request): AudioTextResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new AudioTextResponse('Async transcript');
    }

    public function withResponse(StructuredResponse|TextResponse|EmbeddingResponse|ImageResponse|AudioTextResponse|ProviderIdResponse $response): Provider
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param  array<int, ProviderResponse>  $responses
     */
    public function withResponseChain(array $responses): Provider
    {

        $this->responses = $responses;

        return $this;
    }
}
