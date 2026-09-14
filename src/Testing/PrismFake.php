<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Closure;
use Exception;
use Generator;
use PHPUnit\Framework\Assert as PHPUnit;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Images\Request as ImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\Concerns\CanGenerateFakeChunksFromTextResponses;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class PrismFake extends Provider
{
    use CanGenerateFakeChunksFromTextResponses;

    protected int $responseSequence = 0;

    /** @var array<int, StructuredRequest|TextRequest|EmbeddingRequest|ImageRequest|TextToSpeechRequest|SpeechToTextRequest> */
    protected array $recorded = [];

    /** @var array<string, mixed> */
    protected array $providerConfig = [];

    /**
     * @param  array<int, TextResponse|StructuredResponse|EmbeddingResponse|ImageResponse|AudioResponse|AudioTextResponse|ModerationResponse>  $responses
     */
    public function __construct(protected array $responses = []) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->recorded[] = $request;

        return $this->nextTextResponse() ?? new TextResponse(
            steps: collect([]),
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: collect([]),
            additionalContent: [],
        );
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->recorded[] = $request;

        return $this->nextEmbeddingResponse() ?? new EmbeddingResponse(
            embeddings: [],
            usage: new EmbeddingsUsage(10),
            meta: new Meta('fake-id', 'fake-model'),
        );
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->recorded[] = $request;

        return $this->nextStructuredResponse() ?? new StructuredResponse(
            steps: collect([]),
            text: '',
            structured: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            additionalContent: [],
        );
    }

    #[\Override]
    public function images(ImageRequest $request): ImageResponse
    {
        $this->recorded[] = $request;

        return $this->nextImageResponse() ?? new ImageResponse(
            images: [
                new GeneratedImage(
                    url: 'https://example.com/fake-image.png',
                    revisedPrompt: null,
                ),
            ],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            additionalContent: [],
        );
    }

    #[\Override]
    public function textToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $this->recorded[] = $request;

        return $this->nextAudioResponse() ?? new AudioResponse(
            audio: new GeneratedAudio(
                base64: 'ZmFrZS1hdWRpby1jb250ZW50',
                type: 'audio/mpeg'
            ),
            additionalContent: [],
        );
    }

    #[\Override]
    public function speechToText(SpeechToTextRequest $request): AudioTextResponse
    {
        $this->recorded[] = $request;

        return $this->nextAudioTextResponse() ?? new AudioTextResponse(
            text: 'fake transcribed text',
            usage: new Usage(0, 0),
            additionalContent: [],
        );
    }

    /**
     * Fake implementation of the streaming endpoint.
     *
     * Behavior:
     *  1. Records the incoming {@link TextRequest}
     *  2. Pulls the next fixture from the list supplied to {@see Prism::fake()}.
     *  3. Yields an appropriate stream of events.
     *
     * Supported fixture type:
     *  • {@link TextResponse} – auto-chunked into stream events.
     *
     * @return Generator<StreamEvent>
     *
     * @throws Exception if the fixture type is unknown or no fixture remains.
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $this->recorded[] = $request;

        $fixture = $this->nextTextResponse() ?? new TextResponse(
            steps: collect([]),
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: collect([]),
            additionalContent: [],
        );

        yield from $this->streamEventsFromTextResponse($fixture, $request);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setProviderConfig(array $config): void
    {
        $this->providerConfig = $config;
    }

    /**
     * @param  Closure(array<int, StructuredRequest|TextRequest|EmbeddingRequest|ImageRequest|TextToSpeechRequest|SpeechToTextRequest>):void  $fn
     */
    public function assertRequest(Closure $fn): void
    {
        $fn($this->recorded);
    }

    public function assertPrompt(string $prompt): void
    {
        $prompts = collect($this->recorded)
            ->flatten()
            ->map(fn ($response) => $response->prompt());

        PHPUnit::assertTrue(
            $prompts->contains($prompt),
            "Could not find the prompt {$prompt} in the recorded requests"
        );
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function assertProviderConfig(array $providerConfig): void
    {
        PHPUnit::assertEqualsCanonicalizing(
            $providerConfig,
            $this->providerConfig
        );
    }

    /**
     * Assert number of calls made
     */
    public function assertCallCount(int $expectedCount): void
    {
        $actualCount = count($this->recorded ?? []);

        PHPUnit::assertSame($expectedCount, $actualCount, "Expected {$expectedCount} calls, got {$actualCount}");
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function streamEventsFromTextResponse(TextResponse $response, TextRequest $request): Generator
    {
        $messageId = EventID::generate();

        yield new StreamStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            model: $request->model(),
            provider: 'fake'
        );

        yield new StepStartEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        if ($response->steps->isNotEmpty()) {
            $stepIndex = 0;
            $totalSteps = $response->steps->count();

            foreach ($response->steps as $step) {
                if ($step->text !== '') {
                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $messageId
                    );

                    foreach ($this->convertStringToTextChunkGenerator($step->text, $this->fakeChunkSize) as $chunk) {
                        yield new TextDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $chunk->text,
                            messageId: $messageId
                        );
                    }

                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $messageId
                    );
                }

                foreach ($step->toolCalls as $toolCall) {
                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: $toolCall,
                        messageId: $messageId
                    );
                }

                foreach ($step->toolResults as $toolResult) {
                    yield new ToolResultEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolResult: $toolResult,
                        messageId: $messageId,
                        success: true
                    );
                }

                $stepIndex++;

                // If this step has tool calls/results and there are more steps, end current step and start new one
                if (($step->toolCalls !== [] || $step->toolResults !== []) && $stepIndex < $totalSteps) {
                    yield new StepFinishEvent(
                        id: EventID::generate(),
                        timestamp: time()
                    );
                    yield new StepStartEvent(
                        id: EventID::generate(),
                        timestamp: time()
                    );
                }
            }
        } elseif ($response->text !== '') {
            yield new TextStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $messageId
            );
            foreach ($this->convertStringToTextChunkGenerator($response->text, $this->fakeChunkSize) as $chunk) {
                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $chunk->text,
                    messageId: $messageId
                );
            }
            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $messageId
            );
        }

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $response->usage
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $response->finishReason,
            usage: $response->usage
        );
    }

    protected function nextTextResponse(): ?TextResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var array<int, TextResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextStructuredResponse(): ?StructuredResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var array<int, StructuredResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextEmbeddingResponse(): ?EmbeddingResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var EmbeddingResponse[] $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextImageResponse(): ?ImageResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var array<int, ImageResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextAudioResponse(): ?AudioResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var array<int, AudioResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextAudioTextResponse(): ?AudioTextResponse
    {
        if ($this->responses === []) {
            return null;
        }

        /** @var array<int, AudioTextResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }
}
