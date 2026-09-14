<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Media;

/**
 * Note: Prism currently only supports Documents with Anthropic and OpenRouter.
 */
class Document extends Media
{
    public const KIND = 'document';

    protected ?string $documentTitle = null;

    /**
     * @var null|array<string>
     */
    protected ?array $chunks = null;

    public static function fromFileId(string $fileId, ?string $title = null): static
    {
        return parent::fromFileId($fileId)->setDocumentTitle($title);
    }

    /**
     * @deprecated Use `fromLocalPath()` instead.
     */
    public static function fromPath(string $path, ?string $title = null): static
    {
        return self::fromLocalPath($path, $title);
    }

    public static function fromLocalPath(string $path, ?string $title = null): static
    {
        return parent::fromLocalPath($path)->setDocumentTitle($title);
    }

    public static function fromStoragePath(string $path, ?string $diskName = null, ?string $title = null): static
    {
        return parent::fromStoragePath($path, $diskName)->setDocumentTitle($title);
    }

    public static function fromUrl(string $url, ?string $title = null): static
    {
        return parent::fromUrl($url)->setDocumentTitle($title);
    }

    public static function fromRawContent(string $rawContent, ?string $mimeType = null, ?string $title = null): static
    {
        return parent::fromRawContent($rawContent, $mimeType)->setDocumentTitle($title);
    }

    public static function fromBase64(string $document, ?string $mimeType = null, ?string $title = null): static
    {
        return parent::fromBase64($document, $mimeType)->setDocumentTitle($title);
    }

    public static function fromText(string $text, ?string $title = null): static
    {
        return self::fromRawContent($text, 'text/plain', $title);
    }

    /**
     * @param  array<string>  $chunks
     */
    public static function fromChunks(array $chunks, ?string $title = null): self
    {
        $document = new self;
        $document->chunks = $chunks;
        $document->documentTitle = $title;

        return $document;
    }

    public function isChunks(): bool
    {
        return $this->chunks !== null;
    }

    public function setDocumentTitle(?string $title): static
    {
        $this->documentTitle = $title;

        return $this;
    }

    public function documentTitle(): ?string
    {
        return $this->documentTitle;
    }

    /**
     * @return null|array<mixed>
     */
    public function chunks(): ?array
    {
        return $this->chunks;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'document_title' => $this->documentTitle,
            'chunks' => $this->chunks,
        ]);
    }
}
