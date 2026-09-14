<?php

declare(strict_types=1);

namespace Prism\Prism;

use Illuminate\Support\Traits\Macroable;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Batch\PendingRequest as PendingBatchRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Files\PendingRequest as PendingFilesRequest;
use Prism\Prism\Fim\PendingRequest as PendingFimRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;

class Prism
{
    use Macroable;

    public function text(): PendingTextRequest
    {
        return new PendingTextRequest;
    }

    public function structured(): PendingStructuredRequest
    {
        return new PendingStructuredRequest;
    }

    public function embeddings(): PendingEmbeddingRequest
    {
        return new PendingEmbeddingRequest;
    }

    public function image(): PendingImageRequest
    {
        return new PendingImageRequest;
    }

    public function audio(): PendingAudioRequest
    {
        return new PendingAudioRequest;
    }

    public function moderation(): PendingModerationRequest
    {
        return new PendingModerationRequest;
    }

    public function files(): PendingFilesRequest
    {
        return new PendingFilesRequest;
    }

    public function batch(): PendingBatchRequest
    {
        return new PendingBatchRequest;
    }

    public function fim(): PendingFimRequest
    {
        return new PendingFimRequest;
    }
}
