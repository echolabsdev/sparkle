<?php

namespace Prism\Prism\Contracts;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Media;

abstract class ProviderMediaMapper
{
    public function __construct(public readonly Media $media)
    {
        $this->runValidation();
    }

    abstract public function toPayload(): mixed;

    abstract protected function provider(): string|Provider;

    abstract protected function validateMedia(): bool;

    protected function runValidation(): void
    {
        if ($this->validateMedia() === false) {
            $providerName = $this->provider() instanceof Provider ? $this->provider()->value : $this->provider();

            // A URL-only payload refused by a provider that needs bytes gets the
            // reason and the way out, not the generic sentence. This refusal is
            // NEW: Prism used to fetch the URL for you, through an unguarded
            // request, which made a request-derived URL a server-side fetch.
            // Somebody hitting it after upgrading needs to know what changed
            // and what to call, and the generic message tells them neither.
            if ($this->media->isUrl() && ! $this->media->hasRawContent()) {
                throw new PrismException(
                    "The $providerName provider needs this media's bytes, and it was built from a URL. Prism no "
                    .'longer fetches a URL implicitly — reading media used to fetch it automatically, through an '
                    .'unguarded request, which made any request-derived URL a server-side fetch. If you trust this '
                    .'URL, call fetchUrlContent() on the media before sending it.'
                );
            }

            $calledClass = static::class;

            throw new PrismException("The $providerName provider does not support the mediums available in the provided `$calledClass`. Please consult the Prism documentation for more information on which mediums the $providerName provider supports.");
        }
    }
}
