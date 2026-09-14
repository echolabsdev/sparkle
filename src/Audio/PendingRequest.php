<?php

declare(strict_types=1);

namespace Prism\Prism\Audio;

use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\ValueObjects\Media\Audio;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use HasProviderOptions;

    protected string|Audio|int $input;

    protected string $voice;

    public function withInput(string|Audio|int $input): self
    {
        $this->input = $input;

        return $this;
    }

    public function withVoice(string $voice): self
    {
        $this->voice = $voice;

        return $this;
    }

    public function asAudio(): AudioResponse
    {
        $request = $this->toTextToSpeechRequest();

        try {
            return $this->provider->textToSpeech($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function asText(): TextResponse
    {
        $request = $this->toSpeechToTextRequest();

        try {
            return $this->provider->speechToText($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function asTextProviderId(): ProviderIdResponse
    {
        $request = $this->toSpeechToTextRequest();

        try {
            return $this->provider->speechToTextProviderId($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function asTextAsync(): TextResponse
    {
        $request = $this->toSpeechToTextAsyncRequest();

        try {
            return $this->provider->speechToTextAsync($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toTextToSpeechRequest(): TextToSpeechRequest
    {
        if (! is_string($this->input)) {
            throw new InvalidArgumentException('Text-to-speech requires string input');
        }

        return new TextToSpeechRequest(
            model: $this->model,
            providerKey: $this->providerKey(),
            input: $this->input,
            voice: $this->voice,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
        );
    }

    public function toSpeechToTextRequest(): SpeechToTextRequest
    {
        if (! ($this->input instanceof Audio)) {
            throw new InvalidArgumentException('Speech-to-text requires Audio input');
        }

        return new SpeechToTextRequest(
            model: $this->model,
            providerKey: $this->providerKey(),
            input: $this->input,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
        );
    }

    protected function toSpeechToTextAsyncRequest(): SpeechToTextAsyncRequest
    {
        if (! is_string($this->input) && ! is_int($this->input)) {
            throw new InvalidArgumentException('Async speech-to-text requires the input be the Provider ID as a string or integer');
        }

        return new SpeechToTextAsyncRequest(
            model: $this->model,
            providerKey: $this->providerKey(),
            input: $this->input,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
        );
    }
}
