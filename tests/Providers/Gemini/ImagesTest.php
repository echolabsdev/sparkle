<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', ''));
});

it('can generate an image with gemini models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-a-prompt'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('A outsized soda can floating in space')
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(8);
    expect($response->usage->completionTokens)->toBe(1360);
    expect($response->meta->id)->toBe('-ySmaKa-HJfSjMcP8qrtsQw');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.text') === 'A outsized soda can floating in space';
    });
});

it('can edit an image with gemini models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $originalImage = Image::fromLocalPath('tests/Fixtures/diamond.png');

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Add a vaporwave sunset to the background', [$originalImage])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(266);
    expect($response->usage->completionTokens)->toBe(1355);
    expect($response->meta->id)->toBe('vi6maLTBCKbP_uMPm5TcqQI');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        // Verify images come BEFORE text (Gemini best practice)
        // Verify camelCase format (inlineData, mimeType)
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'contents.0.parts.0.inlineData.mimeType') === 'image/png'
            && data_get($data, 'contents.0.parts.1.text') === 'Add a vaporwave sunset to the background';
    });
});

it('can generate an image with imagen models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of a mouse hugging a giraffe.')
        ->generate();

    expect($response->imageCount())->toBe(4);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'Make an image of a mouse hugging a giraffe.';
    });
});

it('can generate an image with imagen models and all options', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen-options'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('Make an image of an elephant hugging a giraffe.')
        ->withProviderOptions([
            'n' => 1,
            'size' => '2K',
            'aspect_ratio' => '16:9',
            'person_generation' => 'dont_allow',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'Make an image of an elephant hugging a giraffe.'
            && data_get($data, 'parameters.sampleCount') === 1
            && data_get($data, 'parameters.sampleImageSize') === '2K'
            && data_get($data, 'parameters.aspectRatio') === '16:9'
            && data_get($data, 'parameters.personGeneration') === 'dont_allow';
    });
});

it('can generate an image with image flash models and all options', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent',
        'gemini/generate-image-with-a-prompt'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Make an image of an elephant hugging a giraffe.')
        ->withProviderOptions([
            'aspect_ratio' => '16:9',
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(8);
    expect($response->usage->completionTokens)->toBe(1360);
    expect($response->meta->id)->toBe('-ySmaKa-HJfSjMcP8qrtsQw');
    expect($response->meta->model)->toBe('gemini-2.0-flash-preview-image-generation');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent'
            && data_get($data, 'generationConfig.imageConfig.aspectRatio') === '16:9';
    });
});

it('can generate multiple images with imagen models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/imagen-4.0-generate-001:predict',
        'gemini/generate-image-with-a-prompt-imagen-multiple'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'imagen-4.0-generate-001')
        ->withPrompt('I need an image of a worm in a suit.')
        ->withProviderOptions([
            'n' => 3,
        ])
        ->generate();

    expect($response->imageCount())->toBe(3);
    expect($response->firstImage())->not->toBeNull();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict'
            && data_get($data, 'instances.0.prompt') === 'I need an image of a worm in a suit.'
            && data_get($data, 'parameters.sampleCount') === 3;
    });
});

it('sends images before text for gemini-2.5-flash-image (best practice)', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.5-flash-image:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $originalImage = Image::fromLocalPath('tests/Fixtures/diamond.png');

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.5-flash-image')
        ->withPrompt('Transform this diamond into a ruby', [$originalImage])
        ->generate();

    expect($response->imageCount())->toBe(1);

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $parts = data_get($data, 'contents.0.parts', []);

        // Verify images are sent BEFORE text (Gemini best practice)
        // Verify camelCase format (inlineData, mimeType) per official API spec
        // parts[0] should be the image (inlineData)
        // parts[1] should be the text
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent'
            && isset($parts[0]['inlineData'])
            && data_get($parts, '0.inlineData.mimeType') === 'image/png'
            && isset($parts[1]['text'])
            && data_get($parts, '1.text') === 'Transform this diamond into a ruby';
    });
});

it('can pass safety_settings via provider options for gemini models', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.5-flash-image:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $originalImage = Image::fromLocalPath('tests/Fixtures/diamond.png');

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.5-flash-image')
        ->withPrompt('Transform this into a safe image', [$originalImage])
        ->withProviderOptions([
            'safety_settings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        // Verify safety_settings is present in the request
        $safetySettings = data_get($data, 'safetySettings');

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent'
            && isset($safetySettings)
            && is_array($safetySettings)
            && count($safetySettings) === 4
            && data_get($safetySettings, '0.category') === 'HARM_CATEGORY_HARASSMENT'
            && data_get($safetySettings, '0.threshold') === 'BLOCK_ONLY_HIGH'
            && data_get($safetySettings, '1.category') === 'HARM_CATEGORY_HATE_SPEECH'
            && data_get($safetySettings, '2.category') === 'HARM_CATEGORY_SEXUALLY_EXPLICIT'
            && data_get($safetySettings, '3.category') === 'HARM_CATEGORY_DANGEROUS_CONTENT';
    });
});

it('uses default responseModalities when not specified', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.5-flash-image:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.5-flash-image')
        ->withPrompt('Generate an image')
        ->generate();

    expect($response->imageCount())->toBe(1);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        // Verify default responseModalities is ['TEXT', 'IMAGE']
        $modalities = data_get($data, 'generationConfig.responseModalities');

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent'
            && isset($modalities)
            && is_array($modalities)
            && $modalities === ['TEXT', 'IMAGE'];
    });
});

it('can override responseModalities via provider options', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1beta/models/gemini-2.5-flash-image:generateContent',
        'gemini/generate-image-with-image-edit'
    );

    $response = Prism::image()
        ->using(Provider::Gemini, 'gemini-2.5-flash-image')
        ->withPrompt('Generate an image only')
        ->withProviderOptions([
            'response_modalities' => ['IMAGE'],
        ])
        ->generate();

    expect($response->imageCount())->toBe(1);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        // Verify custom responseModalities is used
        $modalities = data_get($data, 'generationConfig.responseModalities');

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent'
            && isset($modalities)
            && is_array($modalities)
            && $modalities === ['IMAGE']
            && count($modalities) === 1;
    });
});
it('refuses a url input image for generation without fetching it', function (): void {
    // G-44, and a path no validation ever covered: this map is not a
    // ProviderMediaMapper, so no validateMedia() ran, and it inlines input images
    // unconditionally. A URL input image used to be fetched through an unguarded
    // request; with the fetch gone it would have inlined `null` without a word.
    Http::fake(['*' => Http::response('never used')]);

    expect(fn () => Prism::image()
        ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
        ->withPrompt('Add a sunset', [Image::fromUrl('http://169.254.169.254/latest/meta-data/')])
        ->generate())
        ->toThrow(PrismException::class, 'fetchUrlContent()');

    Http::assertSentCount(0);
});
