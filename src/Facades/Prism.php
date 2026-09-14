<?php

declare(strict_types=1);

namespace Prism\Prism\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Response as TextResponse;

/**
 * @method static PendingTextRequest text()
 * @method static PendingStructuredRequest structured()
 * @method static PendingEmbeddingRequest embeddings()
 * @method static PendingImageRequest image()
 * @method static PendingAudioRequest audio()
 * @method static PendingModerationRequest moderation()
 * @method static \Prism\Prism\Files\PendingRequest files()
 * @method static \Prism\Prism\Batch\PendingRequest batch()
 *
 * @see \Prism\Prism\Prism
 */
class Prism extends Facade
{
    /**
     * @param  array<int, TextResponse|StructuredResponse|EmbeddingResponse|ImageResponse|AudioResponse|AudioTextResponse|ModerationResponse>  $responses
     */
    public static function fake(array $responses = []): PrismFake
    {
        $fake = new PrismFake($responses);

        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(
                private readonly PrismFake $fake
            ) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): PrismFake
            {
                $this->fake->setProviderConfig($providerConfig);

                return $this->fake;
            }
        });

        return $fake;
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     */
    public static function provider(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        return app(PrismManager::class)->resolve($name, $providerConfig);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'prism';
    }
}
