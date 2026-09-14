<?php

declare(strict_types=1);

namespace Prism\Prism\Files;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    public function upload(string $content, string $filename, ?string $mimeType = null): FileData
    {
        $request = $this->toUploadRequest($content, $filename, $mimeType);

        try {
            return $this->provider->uploadFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function list(?int $limit = null, ?string $afterId = null, ?string $beforeId = null): FileListResult
    {
        $request = $this->toListRequest($limit, $afterId, $beforeId);

        try {
            return $this->provider->listFiles($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function getMetadata(string $fileId): FileData
    {
        $request = $this->toGetMetadataRequest($fileId);

        try {
            return $this->provider->getFileMetadata($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function delete(string $fileId): DeleteFileResult
    {
        $request = $this->toDeleteRequest($fileId);

        try {
            return $this->provider->deleteFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function download(string $fileId): string
    {
        $request = $this->toDownloadRequest($fileId);

        try {
            return $this->provider->downloadFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException('', $e);
        }
    }

    public function toUploadRequest(string $content, string $filename, ?string $mimeType = null): UploadFileRequest
    {
        return (new UploadFileRequest(
            filename: $filename,
            content: $content,
            mimeType: $mimeType,
        ))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toListRequest(?int $limit = null, ?string $afterId = null, ?string $beforeId = null): ListFilesRequest
    {
        return (new ListFilesRequest(
            limit: $limit,
            afterId: $afterId,
            beforeId: $beforeId,
        ))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toGetMetadataRequest(string $fileId): GetFileMetadataRequest
    {
        return (new GetFileMetadataRequest(fileId: $fileId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toDeleteRequest(string $fileId): DeleteFileRequest
    {
        return (new DeleteFileRequest(fileId: $fileId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }

    public function toDownloadRequest(string $fileId): DownloadFileRequest
    {
        return (new DownloadFileRequest(fileId: $fileId))
            ->withClientOptions($this->clientOptions)
            ->withClientRetry(...$this->clientRetry)
            ->withProviderOptions($this->providerOptions);
    }
}
