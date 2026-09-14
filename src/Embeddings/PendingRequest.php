<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasTelemetryMetadata;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;
    use HasTelemetryMetadata;

    /** @var array<Content> */
    protected array $contents = [];

    public function fromInput(string $input): self
    {
        $this->contents[] = Content::make([$input]);

        return $this;
    }

    /**
     * @param  array<string>  $inputs
     */
    public function fromArray(array $inputs): self
    {
        foreach ($inputs as $input) {
            $this->fromInput($input);
        }

        return $this;
    }

    public function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new PrismException(sprintf('%s is not a valid file', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new PrismException(sprintf('%s contents could not be read', $path));
        }

        return $this->fromInput($contents);
    }

    /**
     * Add an image for embedding generation.
     *
     * Note: Not all providers support image embeddings. Check the provider's
     * documentation to ensure the model you're using supports image input.
     * Common providers that support image embeddings include CLIP-based models
     * and multimodal embedding models like BGE-VL.
     */
    public function fromImage(Image $image): self
    {
        $this->contents[] = Content::make([$image]);

        return $this;
    }

    /**
     * @param  array<Image>  $images
     */
    public function fromImages(array $images): self
    {
        foreach ($images as $image) {
            $this->fromImage($image);
        }

        return $this;
    }

    public function fromAudio(Audio $audio): self
    {
        $this->contents[] = Content::make([$audio]);

        return $this;
    }

    /**
     * @param  array<Audio>  $audios
     */
    public function fromAudios(array $audios): self
    {
        foreach ($audios as $audio) {
            $this->fromAudio($audio);
        }

        return $this;
    }

    public function fromVideo(Video $video): self
    {
        $this->contents[] = Content::make([$video]);

        return $this;
    }

    /**
     * @param  array<Video>  $videos
     */
    public function fromVideos(array $videos): self
    {
        foreach ($videos as $video) {
            $this->fromVideo($video);
        }

        return $this;
    }

    public function fromDocument(Document $document): self
    {
        $this->contents[] = Content::make([$document]);

        return $this;
    }

    /**
     * @param  array<Document>  $documents
     */
    public function fromDocuments(array $documents): self
    {
        foreach ($documents as $document) {
            $this->fromDocument($document);
        }

        return $this;
    }

    /**
     * @param  array<int, Media|Text|string>  $parts
     */
    public function fromContent(array $parts): self
    {
        $this->contents[] = Content::make($parts);

        return $this;
    }

    /**
     * @param  array<int, array<int, Media|Text|string>>  $contents
     */
    public function fromContents(array $contents): self
    {
        foreach ($contents as $content) {
            $this->fromContent($content);
        }

        return $this;
    }

    /**
     * @deprecated Use `asEmbeddings` instead.
     */
    public function generate(): Response
    {
        return $this->asEmbeddings();
    }

    public function asEmbeddings(): Response
    {
        if ($this->contents === []) {
            throw new PrismException('Embeddings input is required (text, images, audio, video, documents, or content parts)');
        }

        $request = $this->toRequest();

        $context = Telemetry::start(TelemetryOperation::Embeddings, $this->providerKey(), $request->model(), $request, $this->telemetryUserId, $this->telemetrySessionId);

        try {
            $response = $this->provider->embeddings($request);

            Telemetry::completed($context, $response);

            return $response;
        } catch (RequestException $e) {
            Telemetry::failed($context, $e);

            $this->provider->handleRequestException($request->model(), $e);
        } finally {
            Telemetry::end($context);
        }
    }

    protected function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
            contents: $this->contents,
        );
    }
}
