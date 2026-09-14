<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Artifact;

it('constructs with required parameters', function (): void {
    $artifact = new Artifact(
        data: 'aGVsbG8gd29ybGQ=',
        mimeType: 'text/plain',
    );

    expect($artifact->data)->toBe('aGVsbG8gd29ybGQ=')
        ->and($artifact->mimeType)->toBe('text/plain')
        ->and($artifact->metadata)->toBe([])
        ->and($artifact->id)->toBeNull();
});

it('constructs with all parameters', function (): void {
    $artifact = new Artifact(
        data: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        mimeType: 'image/png',
        metadata: ['width' => 1, 'height' => 1],
        id: 'artifact-123',
    );

    expect($artifact->data)->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
        ->and($artifact->mimeType)->toBe('image/png')
        ->and($artifact->metadata)->toBe(['width' => 1, 'height' => 1])
        ->and($artifact->id)->toBe('artifact-123');
});

it('creates artifact from raw content', function (): void {
    $rawContent = 'Hello, World!';
    $artifact = Artifact::fromRawContent(
        content: $rawContent,
        mimeType: 'text/plain',
    );

    expect($artifact->data)->toBe(base64_encode($rawContent))
        ->and($artifact->mimeType)->toBe('text/plain')
        ->and($artifact->rawContent())->toBe($rawContent);
});

it('creates artifact from raw content with all parameters', function (): void {
    $rawContent = 'Binary data here';
    $artifact = Artifact::fromRawContent(
        content: $rawContent,
        mimeType: 'application/octet-stream',
        metadata: ['size' => strlen($rawContent)],
        id: 'bin-artifact-456',
    );

    expect($artifact->data)->toBe(base64_encode($rawContent))
        ->and($artifact->mimeType)->toBe('application/octet-stream')
        ->and($artifact->metadata)->toBe(['size' => strlen($rawContent)])
        ->and($artifact->id)->toBe('bin-artifact-456')
        ->and($artifact->rawContent())->toBe($rawContent);
});

it('returns raw content from base64 data', function (): void {
    $originalContent = 'Test content for base64';
    $artifact = new Artifact(
        data: base64_encode($originalContent),
        mimeType: 'text/plain',
    );

    expect($artifact->rawContent())->toBe($originalContent);
});

it('converts to array with all properties', function (): void {
    $artifact = new Artifact(
        data: 'dGVzdCBkYXRh',
        mimeType: 'text/plain',
        metadata: ['key' => 'value'],
        id: 'artifact-789',
    );

    expect($artifact->toArray())->toEqual([
        'id' => 'artifact-789',
        'data' => 'dGVzdCBkYXRh',
        'mime_type' => 'text/plain',
        'metadata' => (object) ['key' => 'value'],
    ]);
});

it('converts to array with minimal properties', function (): void {
    $artifact = new Artifact(
        data: 'c29tZSBkYXRh',
        mimeType: 'application/json',
    );

    expect($artifact->toArray())->toEqual([
        'id' => null,
        'data' => 'c29tZSBkYXRh',
        'mime_type' => 'application/json',
        'metadata' => new stdClass,
    ]);
});

it('handles binary image data', function (): void {
    // Minimal 1x1 transparent PNG
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $artifact = Artifact::fromRawContent(
        content: $pngData,
        mimeType: 'image/png',
        metadata: ['width' => 1, 'height' => 1, 'format' => 'PNG'],
    );

    expect($artifact->mimeType)->toBe('image/png')
        ->and($artifact->rawContent())->toBe($pngData)
        ->and($artifact->metadata)->toHaveKeys(['width', 'height', 'format']);
});

it('handles complex metadata structures', function (): void {
    $artifact = new Artifact(
        data: 'YXVkaW8gZGF0YQ==',
        mimeType: 'audio/mp3',
        metadata: [
            'duration' => 180.5,
            'bitrate' => 320,
            'tags' => ['music', 'rock'],
            'artist' => ['name' => 'Artist Name', 'albums' => ['Album 1', 'Album 2']],
        ],
    );

    expect($artifact->metadata)->toBe([
        'duration' => 180.5,
        'bitrate' => 320,
        'tags' => ['music', 'rock'],
        'artist' => ['name' => 'Artist Name', 'albums' => ['Album 1', 'Album 2']],
    ]);
});
