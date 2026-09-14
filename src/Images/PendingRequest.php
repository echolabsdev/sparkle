<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasTelemetryMetadata;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use HasPrompts;
    use HasProviderOptions;
    use HasTelemetryMetadata;

    public function generate(): Response
    {
        $request = $this->toRequest();

        $context = Telemetry::start(TelemetryOperation::Image, $this->providerKey(), $request->model(), $request, $this->telemetryUserId, $this->telemetrySessionId);

        try {
            $response = $this->provider->images($request);

            Telemetry::completed($context, $response);

            return $response;
        } catch (RequestException $e) {
            Telemetry::failed($context, $e);

            $this->provider->handleRequestException($request->model(), $e);
        } finally {
            Telemetry::end($context);
        }
    }

    public function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            additionalContent: array_values(array_filter(
                $this->additionalContent,
                fn (Media|Text $content): bool => $content instanceof Image
            )),
            providerOptions: $this->providerOptions,
        );
    }
}
