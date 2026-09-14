<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\Providers\Replicate\Maps\FinishReasonMap;
use Prism\Prism\Providers\Replicate\Maps\MessageMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use HandlesPredictions;

    protected StreamState $state;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60
    ) {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        // Tool calling is not supported with streaming
        if ($request->tools() !== []) {
            throw new PrismException(
                'Replicate: Tool calling is not supported with streaming. '
                .'Use ->generate() instead of ->stream()'
            );
        }

        $this->state->reset()->withMessageId(EventID::generate());

        // Build the prompt from messages
        $prompt = MessageMap::map($request->messages());

        // Prepare the prediction payload with stream enabled
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => array_merge(
                ['prompt' => $prompt],
                $this->buildInputParameters($request)
            ),
            'stream' => true, // Enable streaming
        ];

        // Create prediction
        $prediction = $this->createPrediction($this->client, $payload);

        // Emit stream start
        yield new StreamStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            model: $request->model(),
            provider: 'replicate',
        );

        // Check if streaming URL is available
        $streamUrl = $prediction->urls['stream'] ?? null;

        if ($streamUrl !== null) {
            // Use real-time SSE streaming
            yield from $this->processSSEStream($streamUrl, $prediction->id);
        } else {
            // Fallback to simulated streaming (poll + tokenize)
            $completedPrediction = $this->waitForPrediction(
                $this->client,
                $prediction->id,
                $this->pollingInterval,
                $this->maxWaitTime
            );

            yield from $this->processTokenizedOutput($completedPrediction);
        }
    }

    /**
     * Process real-time SSE stream from Replicate.
     *
     * @return Generator<StreamEvent>
     */
    protected function processSSEStream(string $streamUrl, string $predictionId): Generator
    {
        // Connect to the SSE stream with proper headers
        $response = $this->client
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true])
            ->get($streamUrl);

        $stream = $response->getBody();

        $textStarted = false;
        $finalStatus = 'succeeded';
        $metrics = [];
        $currentEvent = null;

        try {
            while (! $stream->eof()) {
                $line = $this->readLine($stream);
                // Skip empty lines and comments
                if ($line === '') {
                    continue;
                }
                if ($line === "\n") {
                    continue;
                }
                if (str_starts_with($line, ':')) {
                    continue;
                }

                // Parse SSE field
                if (str_starts_with($line, 'event:')) {
                    $currentEvent = trim(substr($line, strlen('event:')));
                } elseif (str_starts_with($line, 'data:')) {
                    $data = substr($line, strlen('data:'));
                    // Remove leading space if present (SSE spec)
                    if (str_starts_with($data, ' ')) {
                        $data = substr($data, 1);
                    }
                    // Remove trailing newline
                    $data = rtrim($data, "\n");

                    // Handle event based on type
                    if ($currentEvent === 'output') {
                        // Text output event (data is plain text)
                        if (! $textStarted) {
                            yield new TextStartEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                messageId: $this->state->messageId()
                            );
                            $textStarted = true;
                        }

                        if ($data !== '') {
                            $this->state->appendText($data);

                            yield new TextDeltaEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                delta: $data,
                                messageId: $this->state->messageId()
                            );
                        }
                    } elseif ($currentEvent === 'done') {
                        // Stream completion event (data is JSON)
                        try {
                            $doneData = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                            $finalStatus = $doneData['status'] ?? 'succeeded';
                            $metrics = $doneData['metrics'] ?? [];
                        } catch (Throwable) {
                            // Empty done event
                            $finalStatus = 'succeeded';
                        }
                        break;
                    } elseif ($currentEvent === 'error') {
                        // Error event (data is JSON)
                        try {
                            $errorData = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                            $errorMessage = $errorData['detail'] ?? $data;
                        } catch (Throwable) {
                            $errorMessage = $data;
                        }
                        throw new PrismException("Replicate streaming error: {$errorMessage}");
                    }

                    // Reset event type after processing
                    $currentEvent = null;
                }
            }
        } finally {
            $stream->close();
        }

        // Emit text complete if text was started
        if ($textStarted) {
            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            );
        }

        // Emit stream end
        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReasonMap::map($finalStatus),
            usage: new Usage(
                promptTokens: $metrics['input_token_count'] ?? 0,
                completionTokens: $metrics['output_token_count'] ?? 0,
            ),
        );
    }

    /**
     * Read a single line from the stream.
     */
    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Process tokenized output as streaming events (fallback method).
     *
     * @param  object{id: string, status: string, output: mixed, error: string|null, metrics: array<string, mixed>}  $prediction
     * @return Generator<StreamEvent>
     */
    protected function processTokenizedOutput(object $prediction): Generator
    {
        $output = $prediction->output ?? [];

        if (! is_array($output)) {
            $output = [$output];
        }

        // Emit text start
        yield new TextStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );

        // Stream each token as a delta
        foreach ($output as $token) {
            if (is_string($token) && $token !== '') {
                $this->state->appendText($token);

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $token,
                    messageId: $this->state->messageId()
                );
            }
        }

        // Emit text complete
        yield new TextCompleteEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );

        // Emit stream end
        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReasonMap::map($prediction->status),
            usage: new Usage(
                promptTokens: $prediction->metrics['input_token_count'] ?? 0,
                completionTokens: $prediction->metrics['output_token_count'] ?? 0,
            ),
        );
    }

    /**
     * Build input parameters from request.
     *
     * @return array<string, mixed>
     */
    protected function buildInputParameters(Request $request): array
    {
        $params = [];

        if ($request->maxTokens()) {
            $params['max_tokens'] = $request->maxTokens();
        }

        // Map provider options
        foreach ($request->providerOptions() as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Extract version from model string.
     */
    /**
     * Extract version ID from model string.
     * Supports formats like "owner/model:version" or just "owner/model".
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Return as-is and let Replicate use the latest version or resolve the format
        return $model;
    }
}
