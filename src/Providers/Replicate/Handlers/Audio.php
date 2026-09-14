<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use HandlesPredictions;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60,
    ) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        // Build input parameters for text-to-speech
        $input = [
            'text' => $request->input(),
        ];

        // Add provider-specific options (voice, speed, etc.)
        $providerOptions = $request->providerOptions();
        if (! empty($providerOptions)) {
            $input = array_merge($input, $providerOptions);
        }

        // Create prediction
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => $input,
        ];

        // Create prediction and wait for completion (uses sync mode if enabled)
        $prediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Check for errors
        if ($prediction->isFailed()) {
            throw new PrismException(
                "Replicate TTS prediction failed: {$prediction->error}"
            );
        }

        // Extract audio URL from output
        $audioUrl = $this->extractAudioUrl($prediction->output);

        if (! $audioUrl) {
            throw new PrismException('No audio output found in Replicate response');
        }

        // Download audio content
        $audioContent = $this->client->get($audioUrl)->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        $audioInput = $request->input()->url();

        // A URL IS SENT AS A URL. Replicate fetches it on its own side.
        //
        // This used to check `is_file($audioInput)` first and, when the "URL"
        // named a file on this machine, read it and upload its bytes. The URL is
        // whatever string the caller passed to `Audio::fromUrl()`, which does not
        // validate a scheme — so a request-derived value of `/etc/passwd` had
        // this process read that file and send it to a third party. That is the
        // same class of defect as G-44, where reading a media URL fetched it
        // server-side, and the release that removed that fetch could not also
        // claim URLs are no longer dereferenced here while this branch existed.
        //
        // A local file has its own constructor, `Audio::fromLocalPath()`, whose
        // bytes arrive below as raw content. That is the route for a path.
        if (! $audioInput && $request->input()->rawContent()) {
            // No URL but we have content (using fromLocalPath)
            $base64 = $request->input()->base64();
            $mimeType = $request->input()->mimeType() ?? 'audio/mpeg';
            $audioInput = "data:{$mimeType};base64,{$base64}";
        }

        // Build input parameters for speech-to-text
        $input = [
            'audio' => $audioInput,
            'task' => 'transcribe',
        ];

        // Add provider-specific options (language, etc.)
        $providerOptions = $request->providerOptions();
        if (! empty($providerOptions)) {
            $input = array_merge($input, $providerOptions);
        }

        // Create prediction
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => $input,
        ];

        // Create prediction and wait for completion (uses sync mode if enabled)
        $prediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Check for errors
        if ($prediction->isFailed()) {
            throw new PrismException(
                "Replicate STT prediction failed: {$prediction->error}"
            );
        }

        // Extract text from output
        $text = $this->extractTextFromOutput($prediction->output);

        return new TextResponse(
            text: $text,
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            additionalContent: [
                'metrics' => $prediction->metrics,
            ],
        );
    }

    /**
     * Extract version ID from model string.
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Otherwise, return as-is and let Replicate use the latest version
        return $model;
    }

    /**
     * Extract audio URL from Replicate output.
     */
    protected function extractAudioUrl(mixed $output): ?string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            // Output might be an array with a URL
            if (isset($output[0]) && is_string($output[0])) {
                return $output[0];
            }

            // Or it might have an 'audio' or 'url' key
            if (isset($output['audio'])) {
                return is_string($output['audio']) ? $output['audio'] : null;
            }

            if (isset($output['url'])) {
                return is_string($output['url']) ? $output['url'] : null;
            }
        }

        return null;
    }

    /**
     * Extract text from Replicate output.
     */
    protected function extractTextFromOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            // Check for common keys
            if (isset($output['text'])) {
                return (string) $output['text'];
            }

            if (isset($output['transcription'])) {
                return (string) $output['transcription'];
            }

            if (isset($output['segments'])) {
                // Whisper-style output with segments
                /** @var array<array{text: string}> $segments */
                $segments = $output['segments'];

                return collect($segments)
                    ->pluck('text')
                    ->join(' ');
            }

            // If it's an array of strings, join them
            if (isset($output[0]) && is_string($output[0])) {
                return implode('', $output);
            }
        }

        return '';
    }
}
