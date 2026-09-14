<?php

namespace Prism\Prism\ValueObjects\Media;

use finfo;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderOptions;

/**
 * @implements Arrayable<string, mixed>
 */
class Media implements Arrayable
{
    use HasProviderOptions;

    /**
     * What this media IS, written into the stored form under `kind`.
     *
     * Each concrete type overrides it — `image`, `audio`, `video`, `document` —
     * with the same key and values both ports store. Null only for a bare
     * `Media`, which is none of the four.
     */
    public const KIND = null;

    protected ?string $fileId = null;

    protected ?string $localPath = null;

    protected ?string $storagePath = null;

    protected ?string $rawContent = null;

    protected ?string $filename = null;

    public function __construct(
        public ?string $url = null,
        public ?string $base64 = null,
        public ?string $mimeType = null) {}

    public static function fromFileId(string $fileId): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;
        $instance->fileId = $fileId;

        return $instance;
    }

    /**
     * @deprecated Use `fromLocalPath()` instead.
     */
    public static function fromPath(string $path): static
    {
        return self::fromLocalPath($path);
    }

    public static function fromLocalPath(string $path, ?string $mimeType = null): static
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("$path is not a file");
        }

        // Not `?: ''` — a file holding just "0" is falsy but not empty.
        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            throw new InvalidArgumentException("$path is empty");
        }

        if (! $mimeType && ! ($mimeType = File::mimeType($path))) {
            throw new InvalidArgumentException("Could not determine mime type for {$path}");
        }

        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->localPath = $path;
        $instance->rawContent = $content;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromStoragePath(string $path, ?string $diskName = null): static
    {
        /** @var FilesystemAdapter */
        $disk = Storage::disk($diskName);

        $diskName ??= 'default';

        if (! $disk->exists($path)) {
            throw new InvalidArgumentException("$path does not exist on the '$diskName' disk");
        }

        $content = $disk->get($path);

        if (! $content) {
            throw new InvalidArgumentException("$path on the '$diskName' disk is empty.");
        }

        $mimeType = $disk->mimeType($path);

        if (! $mimeType) {
            throw new InvalidArgumentException("Could not determine mime type for {$path} on the '$diskName' disk");
        }

        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->storagePath = $path;
        $instance->rawContent = $content;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromUrl(string $url, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->url = $url;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromRawContent(string $rawContent, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->rawContent = $rawContent;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromBase64(string $base64, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->base64 = $base64;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public function as(string $name): self
    {
        $this->filename = $name;

        return $this;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function isFileId(): bool
    {
        return $this->fileId !== null;
    }

    public function isFile(): bool
    {
        return $this->localPath !== null || $this->storagePath !== null;
    }

    public function isUrl(): bool
    {
        return $this->url !== null;
    }

    /**
     * Are the bytes ALREADY IN HAND?
     *
     * This used to delegate to `hasRawContent()`, which answers a different
     * question — "can bytes be obtained" — and therefore returned true for a
     * URL that had never been fetched:
     *
     *     Audio::fromUrl('http://169.254.169.254/…')->hasBase64();  // was true
     *
     * The name states a fact about this object's contents, so it is the natural
     * predicate for a consumer deciding whether sending this media will cause
     * an outbound request. Every such consumer got the opposite of what they
     * asked, silently: the guard passed, the request was built, the fetch
     * happened. A downstream SSRF guard written this way does nothing at all.
     *
     * `fromLocalPath()` and `fromStoragePath()` still answer TRUE, and that is
     * correct rather than an exception — both read the file at construction, so
     * by the time anyone asks, the bytes really are held here.
     *
     * Ask `hasRawContent()` when you meant "can this be resolved". Both ports
     * already spell this the strict way; the reference was the odd one out.
     */
    public function hasBase64(): bool
    {
        return $this->base64 !== null || $this->rawContent !== null;
    }

    public function hasMimeType(): bool
    {
        return $this->mimeType !== null;
    }

    /**
     * Can this payload produce its bytes WITHOUT a network request?
     *
     * A URL used to answer true here, because reading its bytes fetched it.
     * That fetch is gone (see {@see self::rawContent()}), so a URL on its own
     * no longer has content this object can produce — call
     * {@see self::fetchUrlContent()} first if you mean to resolve it.
     *
     * A local or storage path still answers true: both read the file at
     * construction, so the bytes are already held.
     */
    public function hasRawContent(): bool
    {
        if ($this->base64 !== null) {
            return true;
        }
        if ($this->rawContent !== null) {
            return true;
        }

        return $this->isFile();
    }

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function fileId(): ?string
    {
        return $this->fileId;
    }

    public function localPath(): ?string
    {
        return $this->localPath;
    }

    public function storagePath(): ?string
    {
        return $this->storagePath;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * The payload's bytes, or null when they are not already obtainable.
     *
     * READING THIS NEVER MAKES A NETWORK REQUEST. It used to: a URL payload
     * fetched itself here, through a bare `Http::get()` with no host allow-list,
     * no private-address check and redirects followed. And Prism reads media
     * bytes on ordinary provider calls, so an application that built
     * `fromUrl()` from request input and sent it to a provider that inlines
     * media had this process fetch whatever URL it named — a cloud metadata
     * endpoint included. Verified, not supposed.
     *
     * Both ports already refused to do this, and said why in their own words:
     * reading a property must never perform an outbound request. The reference
     * was the outlier and now agrees with them.
     *
     * To resolve a URL, call {@see self::fetchUrlContent()} — explicitly, with a
     * URL you have decided to trust. That call is unguarded in all three
     * languages, deliberately visible rather than implicit.
     *
     * Removing the URL branch also fixed an ordering defect: a payload carrying
     * BOTH a url and base64 used to fetch the url and ignore the bytes it
     * already held.
     */
    public function rawContent(): ?string
    {
        if ($this->rawContent !== null && $this->rawContent !== '') {
            return $this->rawContent;
        }
        if ($this->localPath) {
            // Not `?: null` — "0" is falsy but is real file content.
            $content = file_get_contents($this->localPath);

            $this->rawContent = $content === false ? null : $content;
        } elseif ($this->storagePath) {
            $this->rawContent = Storage::get($this->storagePath);
        } elseif ($this->base64 !== null) {
            $this->rawContent = base64_decode($this->base64);
        }

        return $this->rawContent;
    }

    /**
     * The payload as base64, or null when there are no bytes to encode.
     *
     * Null for a URL-only payload rather than `''`. It used to fetch the URL and
     * encode the result; with no fetch, encoding `(string) null` would cache and
     * return an empty string, which a provider mapper sends as an empty image
     * rather than failing. Null is what both ports return, and it is the value a
     * caller can actually branch on.
     */
    public function base64(): ?string
    {
        if ($this->base64 !== null && $this->base64 !== '') {
            return $this->base64;
        }

        $content = $this->rawContent();

        if ($content === null) {
            return null;
        }

        return $this->base64 = base64_encode($content);
    }

    public function mimeType(): ?string
    {
        if ($this->mimeType === null && $content = $this->rawContent()) {
            $this->mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: null;
        }

        $this->mimeType = match ($this->mimeType) {
            'audio/x-wav', 'audio/wave', 'audio/x-pn-wav', 'audio/vnd.wave' => 'audio/wav',
            default => $this->mimeType,
        };

        return $this->mimeType;
    }

    /**
     * @return resource
     */
    public function resource()
    {
        if ($this->localPath) {
            $resource = fopen($this->localPath, 'r');
            if ($resource === false) {
                throw new InvalidArgumentException("Cannot open file: {$this->localPath}");
            }

            return $resource;
        }

        if ($this->rawContent || $this->base64) {
            return $this->createStreamFromContent($this->rawContent());
        }

        // A URL is NOT fetched to make a stream. This path used to, and every
        // audio handler and OpenAI image edits reach it on an ordinary call —
        // so a request-derived `Audio::fromUrl()` had this process fetch the
        // URL. Refused with the explicit alternative named, because a caller
        // who meant to resolve a trusted URL needs to know how.
        if ($this->url !== null) {
            throw new InvalidArgumentException(
                'Media built from a URL has no bytes to stream, and Prism no longer fetches a URL implicitly. '
                .'Call fetchUrlContent() first, with a URL you have decided to trust — reading media bytes used '
                .'to fetch them automatically, which made any request-derived URL a server-side fetch.'
            );
        }

        throw new InvalidArgumentException('Cannot create resource from media');
    }

    /**
     * Resolve a URL payload's bytes. EXPLICIT, and the only thing that does.
     *
     * Nothing in Prism calls this for you any more. That is the point: reading
     * media bytes used to call it implicitly, which turned a request-derived URL
     * into a server-side fetch on ordinary provider calls.
     *
     * **This method is unguarded** — no host allow-list, no private-address
     * check — exactly as the TypeScript and Python ports' explicit fetch is.
     * Calling it with a URL taken from user input is still a server-side
     * request forgery; the difference is that it is now a line you wrote rather
     * than a side effect of reading a property. Validate the URL first.
     *
     * Returns the payload so the call reads as a step: `$image->fetchUrlContent()`.
     */
    public function fetchUrlContent(): static
    {
        if (! $this->url) {
            return $this;
        }

        /** @var Response */
        $response = Http::get($this->url);
        $content = $response->body();

        if (! $content) {
            throw new InvalidArgumentException("{$this->url} returns no content.");
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! $mimeType) {
            throw new InvalidArgumentException("Could not determine mime type for {$this->url}.");
        }

        $this->rawContent = $content;

        return $this;
    }

    /**
     * The stored form: what a conversation is persisted as, and rebuilt from.
     *
     * The same keys and values as both ports' serialisation, pinned by the
     * `media-roundtrip` suite in prism-parity. Three things changed to get
     * there, and each was a defect rather than a style:
     *
     * - **The bytes are always here.** This used to write the `base64` FIELD,
     *   which is filled only when the media was built from base64 or something
     *   had since called `base64()`. So one object had two stored forms
     *   depending on what read it earlier, and a message saved before it was
     *   sent — a `fromRawContent()` image, a `Document::fromText()` — stored no
     *   content at all and could not be replayed. `base64()` is computed from
     *   bytes already held; it never makes a network request, so a URL-only
     *   payload still stores `null`.
     * - **No file paths.** `local_path` and `storage_path` recorded where the
     *   file lived on the machine that serialised it. Read back, that names a
     *   different file, or none, on any other host — and a temp upload path is
     *   reused. The bytes travel instead.
     * - **A `kind`.** An Image, an Audio and an untitled Document used to
     *   serialise identically, so anything rebuilding a message had to record
     *   the class separately or guess.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'kind' => static::KIND,
            'url' => $this->url,
            'base64' => $this->base64(),
            'mime_type' => $this->mimeType,
            'file_id' => $this->fileId,
            'filename' => $this->filename,
        ];
    }

    /**
     * @return resource
     */
    protected function createStreamFromContent(?string $content)
    {
        if ($content === null) {
            throw new InvalidArgumentException('Cannot create stream from null content');
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('Cannot create memory stream');
        }

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
