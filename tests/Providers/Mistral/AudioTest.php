<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'fake-api-key'));
});

describe('Speech-to-Text', function (): void {
    it('can transcribe audio with voxtral-mini-latest model - base64 - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'mistral/audio-from-base64'
        );

        $audioFile = Audio::fromBase64(
            base64_encode(file_get_contents('tests/Fixtures/slightly-caffeinated-36.mp3'))
        );

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio with voxtral-mini-latest model - from path - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'mistral/audio-from-path'
        );

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('fake-audio-content'), 'audio/mp3');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'audio/transcriptions'));
    });

    it('can transcribe with verbose json response format', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-verbose');

        $audioFile = Audio::fromBase64(base64_encode('detailed-audio-content'), 'audio/m4a');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions([
                'response_format' => 'verbose_json',
            ])
            ->asText();

        expect($response->text)->toBe('The quick brown fox jumps over the lazy dog.');
        expect($response->usage)->not->toBeNull();
        expect($response->usage->completionTokens)->toBe(12);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'audio/transcriptions'));
    });

    it('includes usage information when available', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-usage');

        $audioFile = Audio::fromBase64(base64_encode('usage-test-audio'), 'audio/flac');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->asText();

        expect($response->usage)->not->toBeNull();
        expect($response->usage->promptTokens)->toBe(5);
        expect($response->usage->completionTokens)->toBe(8);
    });

    it('handles transcription without usage information', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-simple');

        $audioFile = Audio::fromBase64(base64_encode('simple-audio'), 'audio/ogg');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Simple transcription without usage data.');
        expect($response->usage)->toBeNull();
    });

    it('can handle complex transcription responses', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-complex');

        $audioFile = Audio::fromBase64(base64_encode('complex-audio'), 'audio/mp3');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions(['response_format' => 'verbose_json'])
            ->asText();

        expect($response->text)->toBe('Complex transcription with metadata.');
        expect($response->text)->not->toBeEmpty();
        expect($response->additionalContent['language'])->toBe('en');
        expect($response->additionalContent['duration'])->toBe(5.2);
        expect($response->additionalContent['segments'])->toHaveCount(2);
    });

    it('can transcribe with diarize parameter', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('usage-test-audio'), 'audio/flac');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions([
                'diarize' => true,
            ])
            ->asText();

        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            $diarizeField = collect($data)->firstWhere('name', 'diarize');

            return str_contains($request->url(), 'audio/transcriptions') &&
                   $diarizeField && $diarizeField['contents'] === true;
        });
    });

    it('can transcribe with language parameter', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('french-audio'), 'audio/wav');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions([
                'language' => 'fr',
            ])
            ->asText();

        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            $languageField = collect($data)->firstWhere('name', 'language');

            return str_contains($request->url(), 'audio/transcriptions') &&
                   $languageField && $languageField['contents'] === 'fr';
        });
    });

    it('can transcribe with temperature parameter', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('audio-with-temperature'), 'audio/mp3');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions([
                'temperature' => 0.2,
            ])
            ->asText();

        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            $temperatureField = collect($data)->firstWhere('name', 'temperature');

            return str_contains($request->url(), 'audio/transcriptions') &&
                   $temperatureField && $temperatureField['contents'] == 0.2;
        });
    });

    it('can transcribe with prompt parameter', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'mistral/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('audio-with-prompt'), 'audio/mp3');

        $response = Prism::audio()
            ->using('mistral', 'voxtral-mini-latest')
            ->withInput($audioFile)
            ->withProviderOptions([
                'prompt' => 'This is a technical discussion about AI.',
            ])
            ->asText();

        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            $promptField = collect($data)->firstWhere('name', 'prompt');

            return str_contains($request->url(), 'audio/transcriptions') &&
                   $promptField && $promptField['contents'] === 'This is a technical discussion about AI.';
        });
    });
});
