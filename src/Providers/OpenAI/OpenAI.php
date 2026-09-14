<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchListResult;
use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Batch\CancelBatchRequest;
use Prism\Prism\Batch\GetBatchResultsRequest;
use Prism\Prism\Batch\ListBatchesRequest;
use Prism\Prism\Batch\RetrieveBatchRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Files\DeleteFileRequest;
use Prism\Prism\Files\DeleteFileResult;
use Prism\Prism\Files\DownloadFileRequest;
use Prism\Prism\Files\FileData;
use Prism\Prism\Files\FileListResult;
use Prism\Prism\Files\GetFileMetadataRequest;
use Prism\Prism\Files\ListFilesRequest;
use Prism\Prism\Files\UploadFileRequest;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Moderation\Request as ModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Handlers\Audio;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\Cancel;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\Create;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\ListBatches;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\Results;
use Prism\Prism\Providers\OpenAI\Handlers\Batch\Retrieve;
use Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Stream as ChatCompletionsStream;
use Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Structured as ChatCompletionsStructured;
use Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Text as ChatCompletionsText;
use Prism\Prism\Providers\OpenAI\Handlers\Embeddings;
use Prism\Prism\Providers\OpenAI\Handlers\Files\Delete as FileDelete;
use Prism\Prism\Providers\OpenAI\Handlers\Files\Download as FileDownload;
use Prism\Prism\Providers\OpenAI\Handlers\Files\GetMetadata as FileGetMetadata;
use Prism\Prism\Providers\OpenAI\Handlers\Files\ListFiles as FileListFiles;
use Prism\Prism\Providers\OpenAI\Handlers\Files\Upload as FileUpload;
use Prism\Prism\Providers\OpenAI\Handlers\Images;
use Prism\Prism\Providers\OpenAI\Handlers\Moderation;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Handlers\Structured;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class OpenAI extends Provider
{
    use InitializesClient;
    use ProcessRateLimits;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        public readonly ?string $organization,
        public readonly ?string $project,
        public readonly string $apiFormat = 'responses',
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = $this->apiFormat === 'chat_completions'
            ? new ChatCompletionsText($this->client($request->clientOptions(), $request->clientRetry()))
            : new Text($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = $this->apiFormat === 'chat_completions'
            ? new ChatCompletionsStructured($this->client($request->clientOptions(), $request->clientRetry()))
            : new Structured($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function moderation(ModerationRequest $request): ModerationResponse
    {
        $handler = new Moderation($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        $handler = new Audio($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handleTextToSpeech($request);
    }

    #[\Override]
    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        $handler = new Audio($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handleSpeechToText($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = $this->apiFormat === 'chat_completions'
            ? new ChatCompletionsStream($this->client($request->clientOptions(), $request->clientRetry()))
            : new Stream($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    #[\Override]
    public function batch(BatchRequest $request): BatchJob
    {
        return (new Create(
            client: $this->client($request->clientOptions(), $request->clientRetry()),
            uploadFile: fn (string $content, string $filename): FileData => $this->uploadFile(
                (new UploadFileRequest(filename: $filename, content: $content))
                    ->withClientOptions($request->clientOptions())
                    ->withClientRetry(...$request->clientRetry())
                    ->withProviderOptions(['purpose' => 'batch'])
            ),
        ))->handle($request);
    }

    #[\Override]
    public function retrieveBatch(RetrieveBatchRequest $request): BatchJob
    {
        return (new Retrieve($this->client($request->clientOptions(), $request->clientRetry())))->handle($request->batchId);
    }

    #[\Override]
    public function listBatches(ListBatchesRequest $request): BatchListResult
    {
        return (new ListBatches($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    /**
     * @return BatchResultItem[]
     */
    #[\Override]
    public function getBatchResults(GetBatchResultsRequest $request): array
    {
        return (new Results(
            retrieveBatch: fn (string $batchId): BatchJob => $this->retrieveBatch(
                (new RetrieveBatchRequest($batchId))
                    ->withClientOptions($request->clientOptions())
                    ->withClientRetry(...$request->clientRetry())
                    ->withProviderOptions($request->providerOptions())
            ),
            downloadFile: fn (string $fileId): string => $this->downloadFile(
                (new DownloadFileRequest($fileId))
                    ->withClientOptions($request->clientOptions())
                    ->withClientRetry(...$request->clientRetry())
                    ->withProviderOptions($request->providerOptions())
            ),
        ))->handle($request->batchId);
    }

    #[\Override]
    public function cancelBatch(CancelBatchRequest $request): BatchJob
    {
        return (new Cancel($this->client($request->clientOptions(), $request->clientRetry())))->handle($request->batchId);
    }

    #[\Override]
    public function uploadFile(UploadFileRequest $request): FileData
    {
        return (new FileUpload($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function listFiles(ListFilesRequest $request): FileListResult
    {
        return (new FileListFiles($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function getFileMetadata(GetFileMetadataRequest $request): FileData
    {
        return (new FileGetMetadata($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function deleteFile(DeleteFileRequest $request): DeleteFileResult
    {
        return (new FileDelete($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function downloadFile(DownloadFileRequest $request): string
    {
        return (new FileDownload($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: (int) $e->response->header('retry-after')
            ),
            // 503 alongside 529, because OpenAI now returns it. The provider
            // added "503 Service Unavailable" to its inference endpoints on
            // 2026-09-08, and this arm listed only 529 — so a 503 fell through
            // to the generic handler and surfaced as a PrismException rather
            // than the overloaded exception a caller's retry logic keys on.
            //
            // Every other provider that can return 503 already maps it:
            // Mistral (503, 529), Gemini, OpenRouter, Qwen, Requesty, Vertex.
            // OpenAI was the only one missing, which is exactly the shape of
            // drift that is invisible until someone is retrying in production.
            503, 529 => throw PrismProviderOverloadedException::make(ProviderName::OpenAI),
            413 => throw PrismRequestTooLargeException::make(ProviderName::OpenAI),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];
        $message = data_get($data, 'error.message');
        $message = is_array($message) ? implode(', ', $message) : $message;

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'OpenAI',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type'),
            errorMessage: $message,
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders(array_filter([
                'OpenAI-Organization' => $this->organization,
                'OpenAI-Project' => $this->project,
            ]))
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
