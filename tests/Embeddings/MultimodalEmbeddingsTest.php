<?php

declare(strict_types=1);

namespace Tests\Embeddings;

use Prism\Prism\Embeddings\Content;
use Prism\Prism\Embeddings\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;

// Minimal fixtures for media value objects
$testImageBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$testAudioBase64 = 'SUQzAwAAAAAA';
$testVideoBase64 = 'AAAAIGZ0eXBpc29t';
$testPdfBase64 = 'JVBERi0xLjQKJcfs...';

it('adds audio, video, and document inputs to the embeddings request', function () use ($testAudioBase64, $testVideoBase64, $testPdfBase64): void {
    $pendingRequest = new PendingRequest;

    $result = $pendingRequest
        ->fromAudio(Audio::fromBase64($testAudioBase64, 'audio/mpeg'))
        ->fromVideo(Video::fromBase64($testVideoBase64, 'video/mp4'))
        ->fromDocument(Document::fromBase64($testPdfBase64, 'application/pdf'));

    expect($result)->toBeInstanceOf(PendingRequest::class);
});

it('stores grouped multimodal content entries on the request', function () use ($testImageBase64): void {
    $request = new Request(
        model: 'gemini-embedding-2-preview',
        providerKey: 'gemini',
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
        contents: [
            Content::make([
                'An image of a dog',
                Image::fromBase64($testImageBase64, 'image/png'),
            ]),
        ],
    );

    expect($request->hasContents())->toBeTrue();
    expect($request->contents())->toHaveCount(1);
    expect($request->contents()[0]->parts())->toHaveCount(2);
});

it('supports multiple grouped multimodal content entries', function () use ($testImageBase64): void {
    $request = new Request(
        model: 'gemini-embedding-2-preview',
        providerKey: 'gemini',
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
        contents: [
            Content::make(['The dog is cute']),
            Content::make([Image::fromBase64($testImageBase64, 'image/png')]),
        ],
    );

    expect($request->contents())->toHaveCount(2);
});

it('throws exception when no embeddings content is provided', function (): void {
    $pendingRequest = new PendingRequest;

    expect($pendingRequest->asEmbeddings(...))
        ->toThrow(PrismException::class, 'Embeddings input is required (text, images, audio, video, documents, or content parts)');
});
