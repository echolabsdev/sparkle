<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

/**
 * Captured content with the media bytes taken out.
 *
 * `capture_content` was understood to export text. A message's stored form
 * carries each attachment's bytes, so capturing it would carry an uploaded image
 * or document into telemetry. By default a media part keeps what identifies it
 * (kind, mime type, file id, filename, url) and its bytes become their decoded
 * size, `omitted_bytes`. `capture_media` keeps the bytes.
 *
 * A media part is recognised by its stored SHAPE, not its class, because what
 * reaches here is already an array. prism-opentelemetry applies the same rule in
 * PHP, TypeScript and Python, pinned by prism-parity's
 * `opentelemetry-media-content` corpus.
 */
class MediaContent
{
    protected const KINDS = ['image', 'audio', 'video', 'document'];

    public static function withoutBytes(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (self::isMedia($value) && is_string($value['base64']) && $value['base64'] !== '') {
            $value['omitted_bytes'] = strlen(base64_decode($value['base64']));
            $value['base64'] = null;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::withoutBytes($item);
            }
        }

        return $value;
    }

    /**
     * Either stored shape: `kind` from v0.120.0, or the kindless shape before it,
     * recognisable by `base64` beside `mime_type` and `file_id`.
     *
     * @param  array<array-key, mixed>  $value
     */
    protected static function isMedia(array $value): bool
    {
        if (! array_key_exists('base64', $value)) {
            return false;
        }

        if (isset($value['kind'])) {
            return in_array($value['kind'], self::KINDS, true);
        }

        return array_key_exists('mime_type', $value) && array_key_exists('file_id', $value);
    }
}
