<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\ValueObjects\Artifact;

it('constructs with required parameters', function (): void {
    $artifact = new Artifact(
        data: 'aW1hZ2UgZGF0YQ==',
        mimeType: 'image/png',
        metadata: ['width' => 1024, 'height' => 768],
        id: 'img-123',
    );

    $event = new ArtifactEvent(
        id: 'event-456',
        timestamp: 1640995200,
        artifact: $artifact,
        toolCallId: 'tool-call-789',
        toolName: 'generate_image',
        messageId: 'msg-abc',
    );

    expect($event->id)->toBe('event-456')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->artifact)->toBe($artifact)
        ->and($event->toolCallId)->toBe('tool-call-789')
        ->and($event->toolName)->toBe('generate_image')
        ->and($event->messageId)->toBe('msg-abc');
});

it('returns correct stream event type', function (): void {
    $artifact = new Artifact(
        data: 'dGVzdCBkYXRh',
        mimeType: 'text/plain',
    );

    $event = new ArtifactEvent(
        id: 'event-123',
        timestamp: 1640995200,
        artifact: $artifact,
        toolCallId: 'tool-456',
        toolName: 'test_tool',
        messageId: 'msg-789',
    );

    expect($event->type())->toBe(StreamEventType::Artifact);
});

it('converts to array correctly', function (): void {
    $artifact = new Artifact(
        data: 'cGRmIGNvbnRlbnQ=',
        mimeType: 'application/pdf',
        metadata: ['pages' => 5, 'title' => 'Report'],
        id: 'pdf-doc-001',
    );

    $event = new ArtifactEvent(
        id: 'event-123',
        timestamp: 1640995200,
        artifact: $artifact,
        toolCallId: 'tool-456',
        toolName: 'generate_pdf',
        messageId: 'msg-789',
    );

    $array = $event->toArray();

    expect($array)->toEqual([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'tool_call_id' => 'tool-456',
        'tool_name' => 'generate_pdf',
        'message_id' => 'msg-789',
        'artifact' => [
            'id' => 'pdf-doc-001',
            'data' => 'cGRmIGNvbnRlbnQ=',
            'mime_type' => 'application/pdf',
            'metadata' => (object) ['pages' => 5, 'title' => 'Report'],
        ],
    ]);
});

it('handles artifact without id', function (): void {
    $artifact = new Artifact(
        data: 'YXVkaW8gZGF0YQ==',
        mimeType: 'audio/mp3',
    );

    $event = new ArtifactEvent(
        id: 'event-789',
        timestamp: 1640995200,
        artifact: $artifact,
        toolCallId: 'tool-111',
        toolName: 'generate_audio',
        messageId: 'msg-222',
    );

    $array = $event->toArray();

    expect($array['artifact']['id'])->toBeNull()
        ->and($array['artifact']['metadata'])->toEqual(new stdClass);
});

it('handles artifact with complex metadata', function (): void {
    $artifact = new Artifact(
        data: 'aW1hZ2UgZGF0YQ==',
        mimeType: 'image/png',
        metadata: [
            'dimensions' => ['width' => 1920, 'height' => 1080],
            'format' => 'PNG',
            'colorSpace' => 'sRGB',
            'tags' => ['generated', 'ai', 'landscape'],
        ],
        id: 'complex-img',
    );

    $event = new ArtifactEvent(
        id: 'event-999',
        timestamp: 1640995200,
        artifact: $artifact,
        toolCallId: 'tool-complex',
        toolName: 'complex_image_generator',
        messageId: 'msg-complex',
    );

    $array = $event->toArray();

    expect($array['artifact']['metadata'])->toHaveProperties(['dimensions', 'format', 'colorSpace', 'tags'])
        ->and($array['artifact']['metadata']->dimensions)->toBe(['width' => 1920, 'height' => 1080])
        ->and($array['artifact']['metadata']->tags)->toBe(['generated', 'ai', 'landscape']);
});

it('handles various mime types', function (): void {
    $mimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'audio/mp3',
        'audio/wav',
        'video/mp4',
        'application/pdf',
        'application/json',
        'text/plain',
        'text/csv',
    ];

    foreach ($mimeTypes as $mimeType) {
        $artifact = new Artifact(
            data: 'dGVzdA==',
            mimeType: $mimeType,
        );

        $event = new ArtifactEvent(
            id: 'event-mime',
            timestamp: 1640995200,
            artifact: $artifact,
            toolCallId: 'tool-mime',
            toolName: 'mime_test',
            messageId: 'msg-mime',
        );

        expect($event->toArray()['artifact']['mime_type'])->toBe($mimeType);
    }
});
