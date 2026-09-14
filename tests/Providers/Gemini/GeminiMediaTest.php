<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'sss-1234567890'));
});

describe('Media support with Gemini', function (): void {
    it('refuses a url it cannot pass by reference, without fetching it', function (): void {
        // G-44. Gemini takes a URL as a file reference only for YouTube links and
        // Gemini File API URIs. Any other URL used to be fetched and inlined,
        // through an unguarded server-side request. It is now refused with the
        // explicit alternative named, and NOTHING is sent — not the fetch, and
        // not a request to Gemini carrying an empty image.
        Http::fake(['*' => Http::response('never used')]);

        expect(fn () => Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage('What is in this audio', additionalContent: [
                    Audio::fromUrl('http://169.254.169.254/latest/meta-data/'),
                ]),
            ])
            ->asText())
            ->toThrow(PrismException::class, 'fetchUrlContent()');

        Http::assertSentCount(0);
    });

    it('still passes a YouTube url by reference, without fetching it', function (): void {
        // The half of Gemini's URL handling that was never a fetch, and must not
        // have been caught in the change: a recognised URL goes to the provider
        // as a file URI, and no request is made to the URL itself.
        FixtureResponse::fakeResponseSequence('generateContent', 'gemini/media-detection');

        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage('What is in this video', additionalContent: [
                    Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
                ]),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return ($parts[1]['file_data']['file_uri'] ?? null) === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'youtube.com'));
    });

    it('can send media from url for video files', function (): void {
        FixtureResponse::fakeResponseSequence('generateContent', 'gemini/media-detection');

        $videoUrl = 'https://example.com/sample-video.mp4';

        Http::fake([
            $videoUrl => Http::response(
                file_get_contents('tests/Fixtures/sample-video.mp4'),
                200,
                ['Content-Type' => 'video/mp4']
            ),
        ]);

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What is in this video',
                    additionalContent: [
                        // EXPLICIT. Prism fetched this URL implicitly before G-44, through an
                        // unguarded request; the test's intent — send media that lives at a
                        // URL — is kept, by the call a caller now has to write.
                        Video::fromUrl($videoUrl)->fetchUrlContent(),
                    ],
                ),
            ])
            ->asText();

        Http::assertSentInOrder([
            fn (): true => true,
            function (Request $request): bool {
                $message = $request->data()['contents'][0]['parts'];

                expect($message[0])
                    ->toBe([
                        'text' => 'What is in this video',
                    ])
                    ->and($message[1]['inline_data'])->toHaveKeys(['mime_type', 'data'])
                    ->and($message[1]['inline_data']['mime_type'])->toBe('video/mp4')
                    ->and($message[1]['inline_data']['data'])->toBe(
                        base64_encode(file_get_contents('tests/Fixtures/sample-video.mp4'))
                    );

                return true;
            },
        ]);
    });

    it('can create media from raw content with mime type', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $videoContent = file_get_contents('tests/Fixtures/sample-video.mp4');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What is in this video',
                    additionalContent: [
                        Video::fromRawContent($videoContent, 'video/mp4'),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[1]['inline_data']['mime_type'])
                ->toBe('video/mp4')
                ->and($message[1]['inline_data']['data'])->toBe(
                    base64_encode(file_get_contents('tests/Fixtures/sample-video.mp4'))
                );

            return true;
        });
    });

    it('can create media from base64 content', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $videoBase64 = base64_encode(file_get_contents('tests/Fixtures/sample-video.mp4'));

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What is in this video',
                    additionalContent: [
                        Video::fromBase64($videoBase64, 'video/mp4'),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request) use ($videoBase64): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[1]['inline_data']['mime_type'])
                ->toBe('video/mp4')
                ->and($message[1]['inline_data']['data'])->toBe($videoBase64);

            return true;
        });
    });

    it('throws exception for non-existent file with fromLocalPath', function (): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-existent-file.mp4 is not a file');

        Video::fromLocalPath('non-existent-file.mp4');
    });

    it('can send audio from local path', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'Transcribe this audio',
                    additionalContent: [
                        Audio::fromLocalPath('tests/Fixtures/sample-audio.wav'),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[0])
                ->toBe([
                    'text' => 'Transcribe this audio',
                ])
                ->and($message[1]['inline_data'])->toHaveKeys(['mime_type', 'data'])
                ->and($message[1]['inline_data']['mime_type'])->toBe('audio/wav')
                ->and($message[1]['inline_data']['data'])->toBe(
                    base64_encode(file_get_contents('tests/Fixtures/sample-audio.wav'))
                );

            return true;
        });
    });

    it('can send audio from url', function (): void {
        FixtureResponse::fakeResponseSequence('generateContent', 'gemini/media-detection');

        $audioUrl = 'https://example.com/sample-audio.wav';

        Http::fake([
            $audioUrl => Http::response(
                file_get_contents('tests/Fixtures/sample-audio.wav'),
                200,
                ['Content-Type' => 'audio/wav']
            ),
        ]);

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What is in this audio',
                    additionalContent: [
                        // EXPLICIT. Prism fetched this URL implicitly before G-44, through an
                        // unguarded request; the test's intent — send media that lives at a
                        // URL — is kept, by the call a caller now has to write.
                        Audio::fromUrl($audioUrl)->fetchUrlContent(),
                    ],
                ),
            ])
            ->asText();

        Http::assertSentInOrder([
            fn (): true => true,
            function (Request $request): bool {
                $message = $request->data()['contents'][0]['parts'];

                expect($message[0])
                    ->toBe([
                        'text' => 'What is in this audio',
                    ])
                    ->and($message[1]['inline_data'])->toHaveKeys(['mime_type', 'data'])
                    ->and($message[1]['inline_data']['mime_type'])->toBe('audio/wav')
                    ->and($message[1]['inline_data']['data'])->toBe(
                        base64_encode(file_get_contents('tests/Fixtures/sample-audio.wav'))
                    );

                return true;
            },
        ]);
    });

    it('can process audio with raw content', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $audioContent = file_get_contents('tests/Fixtures/sample-audio.wav');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What can you tell me about this audio',
                    additionalContent: [
                        Audio::fromRawContent($audioContent, 'audio/mpeg'),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[1]['inline_data']['mime_type'])
                ->toBe('audio/mpeg')
                ->and($message[1]['inline_data']['data'])->toBe(
                    base64_encode(file_get_contents('tests/Fixtures/sample-audio.wav'))
                );

            return true;
        });
    });

    it('can send youtube url as file uri for videos', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $youtubeUrl = 'https://www.youtube.com/watch?v=cGpQAjhZj9o';

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'Summarize this YouTube video',
                    additionalContent: [
                        Video::fromUrl($youtubeUrl),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request) use ($youtubeUrl): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[0])
                ->toBe([
                    'text' => 'Summarize this YouTube video',
                ])
                ->and($message[1])->toHaveKey('file_data')
                ->and($message[1])->not->toHaveKey('inline_data')
                ->and($message[1]['file_data'])->toBe([
                    'file_uri' => $youtubeUrl,
                ]);

            return true;
        });
    });

    it('can send youtube short url as file uri for videos', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $youtubeUrl = 'https://youtu.be/cGpQAjhZj9o';

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'Summarize this YouTube video',
                    additionalContent: [
                        Video::fromUrl($youtubeUrl),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request) use ($youtubeUrl): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[0])
                ->toBe([
                    'text' => 'Summarize this YouTube video',
                ])
                ->and($message[1])->toHaveKey('file_data')
                ->and($message[1])->not->toHaveKey('inline_data')
                ->and($message[1]['file_data'])->toBe([
                    'file_uri' => $youtubeUrl,
                ]);

            return true;
        });
    });

    it('can send gemini file api uri as file uri', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $geminiFileUri = 'https://generativelanguage.googleapis.com/v1beta/files/abc-123';

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'Describe this video',
                    additionalContent: [
                        Video::fromUrl($geminiFileUri),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request) use ($geminiFileUri): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[0])
                ->toBe([
                    'text' => 'Describe this video',
                ])
                ->and($message[1])->toHaveKey('file_data')
                ->and($message[1])->not->toHaveKey('inline_data')
                ->and($message[1]['file_data'])->toBe([
                    'file_uri' => $geminiFileUri,
                ]);

            return true;
        });
    });

    it('can send video with media_resolution provider option', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $videoContent = file_get_contents('tests/Fixtures/sample-video.mp4');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'What is in this video',
                    additionalContent: [
                        Video::fromRawContent($videoContent, 'video/mp4')
                            ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_HIGH']),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[1]['inline_data']['mime_type'])
                ->toBe('video/mp4')
                ->and($message[1])->toHaveKey('media_resolution')
                ->and($message[1]['media_resolution'])->toBe(['level' => 'MEDIA_RESOLUTION_HIGH']);

            return true;
        });
    });

    it('can send audio with media_resolution provider option', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/media-detection');

        $audioContent = file_get_contents('tests/Fixtures/sample-audio.wav');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withMessages([
                new UserMessage(
                    'Transcribe this audio',
                    additionalContent: [
                        Audio::fromRawContent($audioContent, 'audio/wav')
                            ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_LOW']),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['contents'][0]['parts'];

            expect($message[1])->toHaveKey('media_resolution')
                ->and($message[1]['media_resolution'])->toBe(['level' => 'MEDIA_RESOLUTION_LOW']);

            return true;
        });
    });

});
