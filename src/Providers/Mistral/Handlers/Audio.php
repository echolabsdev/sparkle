<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Concerns\GeneratesAudioFilename;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use GeneratesAudioFilename;
    use ProcessRateLimits;

    public function __construct(protected PendingRequest $client) {}

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                $this->generateFilename($request->input()->mimeType()),
                ['Content-Type' => $request->input()->mimeType()]
            )
            ->post('audio/transcriptions', Arr::whereNotNull([
                'model' => $request->model(),
                'language' => $request->providerOptions('language') ?? null,
                'prompt' => $request->providerOptions('prompt') ?? null,
                'response_format' => $request->providerOptions('response_format') ?? null,
                'diarize' => $request->providerOptions('diarize') ?? null,
                'temperature' => $request->providerOptions('temperature') ?? null,
                'timestamp_granularities' => $request->providerOptions('timestamp_granularities') ?? null,
            ]));

        if (json_validate($response->body())) {
            $data = $response->json();

            if (! $response->successful()) {
                throw new Exception('Failed to transcribe audio: '.$response->body());
            }

            return new TextResponse(
                text: $data['text'] ?? '',
                usage: isset($data['usage'])
                    ? new Usage(
                        promptTokens: $data['usage']['prompt_tokens'] ?? 0,
                        completionTokens: $data['usage']['completion_tokens'] ?? 0,
                    )
                    : null,
                additionalContent: $data,
            );
        }

        // Handle other response formats like vtt
        return new TextResponse(
            text: $response->body(),
        );
    }
}
