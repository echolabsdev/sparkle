<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\ProviderIdResponse;
use Prism\Prism\Audio\SpeechToTextAsyncRequest;
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
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
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
use Prism\Prism\Fim\Request as FimRequest;
use Prism\Prism\Fim\Response as FimResponse;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Moderation\Request as ModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

abstract class Provider
{
    public function text(TextRequest $request): TextResponse
    {
        throw PrismException::unsupportedProviderAction('text', class_basename($this));
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        throw PrismException::unsupportedProviderAction('structured', class_basename($this));
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw PrismException::unsupportedProviderAction('embeddings', class_basename($this));
    }

    public function images(ImagesRequest $request): ImagesResponse
    {
        throw PrismException::unsupportedProviderAction('images', class_basename($this));
    }

    public function moderation(ModerationRequest $request): ModerationResponse
    {
        throw PrismException::unsupportedProviderAction('moderation', class_basename($this));
    }

    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        throw PrismException::unsupportedProviderAction('textToSpeech', class_basename($this));
    }

    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        throw PrismException::unsupportedProviderAction('speechToText', class_basename($this));
    }

    public function speechToTextProviderId(SpeechToTextRequest $request): ProviderIdResponse
    {
        throw PrismException::unsupportedProviderAction('speechToTextProviderId', class_basename($this));
    }

    public function speechToTextAsync(SpeechToTextAsyncRequest $request): SpeechToTextResponse
    {
        throw PrismException::unsupportedProviderAction('speechToTextAsync', class_basename($this));
    }

    public function fim(FimRequest $request): FimResponse
    {
        throw PrismException::unsupportedProviderAction('fim', class_basename($this));
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    public function batch(BatchRequest $request): BatchJob
    {
        throw PrismException::unsupportedProviderAction('batch', class_basename($this));
    }

    public function retrieveBatch(RetrieveBatchRequest $request): BatchJob
    {
        throw PrismException::unsupportedProviderAction('retrieveBatch', class_basename($this));
    }

    public function listBatches(ListBatchesRequest $request): BatchListResult
    {
        throw PrismException::unsupportedProviderAction('listBatches', class_basename($this));
    }

    /**
     * @return BatchResultItem[]
     */
    public function getBatchResults(GetBatchResultsRequest $request): array
    {
        throw PrismException::unsupportedProviderAction('getBatchResults', class_basename($this));
    }

    public function cancelBatch(CancelBatchRequest $request): BatchJob
    {
        throw PrismException::unsupportedProviderAction('cancelBatch', class_basename($this));
    }

    public function uploadFile(UploadFileRequest $request): FileData
    {
        throw PrismException::unsupportedProviderAction('uploadFile', class_basename($this));
    }

    public function listFiles(ListFilesRequest $request): FileListResult
    {
        throw PrismException::unsupportedProviderAction('listFiles', class_basename($this));
    }

    public function getFileMetadata(GetFileMetadataRequest $request): FileData
    {
        throw PrismException::unsupportedProviderAction('getFileMetadata', class_basename($this));
    }

    public function deleteFile(DeleteFileRequest $request): DeleteFileResult
    {
        throw PrismException::unsupportedProviderAction('deleteFile', class_basename($this));
    }

    public function downloadFile(DownloadFileRequest $request): string
    {
        throw PrismException::unsupportedProviderAction('downloadFile', class_basename($this));
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            413 => throw PrismRequestTooLargeException::make(class_basename($this)),
            429 => throw PrismRateLimitedException::make([]),
            529 => throw PrismProviderOverloadedException::make(class_basename($this)),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }
}
