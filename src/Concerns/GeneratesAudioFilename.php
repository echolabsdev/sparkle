<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait GeneratesAudioFilename
{
    protected function generateFilename(?string $mimeType): string
    {
        // Browsers hand back the CONTAINER type, so an audio-only MediaRecorder
        // clip arrives as video/webm or video/mp4, often with codec parameters
        // attached ("audio/webm;codecs=opus"). Strip the parameters and treat
        // the video containers as their audio equivalents, so a recording keeps
        // its real extension instead of being mislabelled .mp3.
        $mimeType = $mimeType === null
            ? null
            : strtolower(trim(explode(';', $mimeType)[0]));

        $extension = match ($mimeType) {
            'audio/flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'video/mp4' => 'mp4',
            'audio/mpga' => 'mpga',
            'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/ogg', 'video/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/wav', 'audio/wave' => 'wav',
            'audio/webm', 'video/webm' => 'webm',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }
}
