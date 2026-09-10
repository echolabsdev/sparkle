<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\ValueObjects\Media\Media;

describe('creation', function (): void {
    it('can create from file ID', function (): void {
        $media = Media::fromFileId('file-id');

        expect($media->fileId())->toBe('file-id');
    });

    it('can create from a local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->localPath())->toBe('tests/Fixtures/diamond.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->storagePath())->toBe('images/test-image.png');
        expect($media->mimeType())->toBe('image/png');
    });

    it('can create from a URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->url())->toBe('https://prismphp.com/storage/diamond.png');
    });

    it('can create from raw content', function (): void {
        $media = Media::fromRawContent('raw-content', 'text/plain');

        expect($media->rawContent())->toBe('raw-content');
        expect($media->mimeType())->toBe('text/plain');
    });

    it('can create from base64', function (): void {
        $media = Media::fromBase64(base64_encode('content'), 'text/plain');

        expect($media->base64())->toBe(base64_encode('content'));
        expect($media->mimeType())->toBe('text/plain');
    });
});

describe('inspection', function (): void {
    test('isFile returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->isFile())->toBeTrue();
    });

    test('isFile returns false for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->isFile())->toBeFalse();
    });

    test('isUrl returns true for URL', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->isUrl())->toBeTrue();
    });

    test('hasRawContent returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    test('hasRawContent returns true for url', function (): void {
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->hasRawContent())->toBeTrue();
    });

    // These two stay TRUE, and are not exceptions to the url case: both
    // constructors read the file eagerly, so the bytes really are in hand by
    // the time anyone asks.
    test('hasBase64 returns true for local path', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns true for storage path', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->hasBase64())->toBeTrue();
    });

    test('hasBase64 returns FALSE for url', function (): void {
        // THIS ASSERTION USED TO READ toBeTrue(), and that is how the defect
        // survived: the behaviour was pinned, so nobody reading the suite could
        // tell it apart from a decision. It was not one — hasBase64() delegated
        // to hasRawContent(), and this test recorded the delegation rather than
        // the question the name asks.
        //
        // A url has no bytes here until something fetches it. Ask
        // hasRawContent() for "can this be resolved"; it is asserted directly
        // above and still true.
        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->hasBase64())->toBeFalse();
    });
});

describe('conversion', function (): void {
    it('converts local path to rawContent', function (): void {
        $media = Media::fromLocalPath('tests/Fixtures/diamond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'));
    });

    it('converts storage path to rawContent', function (): void {
        Storage::fake();

        Storage::put('images/test-image.png', file_get_contents('tests/Fixtures/diamond.png'));

        $media = Media::fromStoragePath('images/test-image.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'));
    });

    it('converts url to rawContent', function (): void {
        Http::fake([
            'https://prismphp.com/storage/diamond.png' => Http::sequence()
                ->push(file_get_contents('tests/Fixtures/diamond.png'))
                ->push(file_get_contents('tests/Fixtures/diamond.png')),
        ])->preventStrayRequests();

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'));
    });

    it('converts url to a resource', function (): void {
        Http::fake([
            'https://prismphp.com/storage/diamond.png' => Http::response(file_get_contents('tests/Fixtures/diamond.png')),
        ])->preventStrayRequests();

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->resource())->toBeResource();
    });

    it('converts base64 to rawContent', function (): void {
        $media = Media::fromBase64(base64_encode('content'), 'text/plain');

        expect($media->rawContent())->toBe('content');
    });

    it('converts rawContent to base64', function (): void {
        $media = Media::fromRawContent('content', 'text/plain');

        expect($media->base64())->toBe(base64_encode('content'));
    });
});

describe('hasBase64 answers whether the bytes are IN HAND', function (): void {
    /*
    | This used to delegate to hasRawContent(), which answers "can bytes be
    | obtained". The difference is invisible until someone writes a guard with
    | it — a consumer building an SSRF check in prism-harness reached for the
    | obvious predicate and it admitted every case the check existed to refuse.
    |
    | Both ports already spell it the strict way. The reference was the outlier,
    | and Media is in no cross-language suite, so nothing caught the divergence.
    */

    it('still says a url CAN be resolved, which is the other question', function (): void {
        expect(Media::fromUrl('https://prismphp.com/storage/diamond.png')->hasRawContent())->toBeTrue();
    });

    it('is TRUE for inline bytes, however they were supplied', function (): void {
        expect(Media::fromBase64(base64_encode('content'), 'text/plain')->hasBase64())->toBeTrue()
            ->and(Media::fromRawContent('content', 'text/plain')->hasBase64())->toBeTrue();
    });

    it('is FALSE for a file id, which only the provider can resolve', function (): void {
        expect(Media::fromFileId('file-abc123')->hasBase64())->toBeFalse();
    });

    it('does not change what a url actually sends', function (): void {
        // The predicate moved; the behaviour did not. rawContent() still
        // fetches, because that is the method that means "resolve it".
        Http::fake([
            'https://prismphp.com/storage/diamond.png' => Http::response(file_get_contents('tests/Fixtures/diamond.png')),
        ])->preventStrayRequests();

        $media = Media::fromUrl('https://prismphp.com/storage/diamond.png');

        expect($media->rawContent())->toBe(file_get_contents('tests/Fixtures/diamond.png'))
            ->and($media->hasBase64())->toBeTrue();
    });
});
