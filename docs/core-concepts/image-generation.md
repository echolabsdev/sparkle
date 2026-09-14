# Image Generation

Generate stunning images from text prompts using AI-powered models. Prism provides a clean, consistent API for image generation across different providers, starting with comprehensive OpenAI support.

## Getting Started

Creating images with Prism is as simple as describing what you want:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
echo $image->url; // https://oaidalleapiprodscus.blob.core.windows.net/...
```

## Provider Support

Currently, Prism supports image generation through:

- **OpenAI**: DALL-E 2, DALL-E 3, and GPT-Image-1 models
- **Gemini**: Gemini 2.0 Flash Preview Image Generation, Imagen 4, Imagen 3
- **Qwen**: Qwen-Image (generation), Qwen-Image-Edit (editing & multi-image fusion)

Additional providers will be added in future releases as the ecosystem evolves.

## Basic Usage

### Simple Generation

The most straightforward way to generate an image:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

// Access the generated image
$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "Image URL: " . $image->url;
}
if ($image->hasBase64()) {
    echo "Base64 Image Data: " . $image->base64;
}
```

### Working with Responses

The response object provides helpful methods for accessing generated content:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-2')
    ->withPrompt('Abstract geometric patterns in vibrant colors')
    ->generate();

// Check if images were generated
if ($response->hasImages()) {
    echo "Generated {$response->imageCount()} image(s)";

    // Access all images
    foreach ($response->images as $image) {
        if ($image->hasUrl()) {
            echo "Image: {$image->url}\n";
        }

        if ($image->hasBase64()) {
            echo "Base64 Image: " . substr($image->base64, 0, 50) . "...\n";
        }

        if ($image->hasRevisedPrompt()) {
            echo "Revised prompt: {$image->revisedPrompt}\n";
        }
    }

    // Or just get the first one
    $firstImage = $response->firstImage();
}

// Check usage information
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Model used: {$response->meta->model}";

// Access the raw API response data
$rawResponse = $response->raw;
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Options

OpenAI offers various customization options depending on the model:

#### DALL-E 3 Options

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A beautiful sunset over mountains')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
        'response_format' => 'url',     // url, b64_json
    ])
    ->generate();
```

#### GPT-Image-1 (Base64 Only)

The GPT-Image-1 model always returns base64-encoded images, regardless of the `response_format` setting:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1024x1024',              // 1024x1024, 1536x1024, 1024x1536, auto
        'quality' => 'high',                // auto, high, medium, low
        'background' => 'transparent',      // transparent, opaque, auto
        'output_format' => 'png',           // png, jpeg, webp
        'output_compression' => 90,         // 0-100 (for jpeg/webp)
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    // Save the base64 image to a file
    file_put_contents('generated-image.png', base64_decode($image->base64));
    echo "Base64 image saved to generated-image.png";
}
```

#### Base64 vs URL Responses

Different models return images in different formats:

- **GPT-Image-1**: Always returns base64-encoded images in the `base64` property
- **DALL-E 2 & 3**: Return URLs by default, but can return base64 when `response_format` is set to `'b64_json'`

```php
// Request base64 format from DALL-E 3
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('Abstract art')
    ->withProviderOptions([
        'response_format' => 'b64_json',
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    echo "Received base64 image data";
}
```

#### Image Editing

OpenAI's `gpt-image-1` model supports editing existing images. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('photos/landscape.png');

$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Add a vaporwave sunset to the background', [$originalImage])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();

// The edited image is returned as base64
$editedImage = $response->firstImage();
file_put_contents('edited-landscape.png', base64_decode($editedImage->base64));
```

You can edit multiple images at once:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Make the colors more vibrant', [
        Image::fromLocalPath('photo1.png'),
        Image::fromLocalPath('photo2.png')->as('custom-name.png'),
    ])
    ->generate();
```

For precise edits, use a mask to specify which areas to modify:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Replace the sky with a starry night', [
        Image::fromLocalPath('landscape.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('sky-mask.png'), // White areas will be edited
        'size' => '1024x1024',
        'output_format' => 'png',
    ])
    ->generate();
```

> [!NOTE]
> The mask should be a PNG image where white pixels indicate areas to edit and transparent pixels indicate areas to preserve.

### Gemini Options

Gemini offers customizations, depending on what model is selected. All Gemini image generation models return base64-encoded images only. They also return `mimeType`.

### Gemini Flash Preview Image Generation

Gemini conversational image generation provides the option to edit images by passing them as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('image/boots.png');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Actually, could we make those boots red?', [$originalImage])
    ->generate();
```

### Imagen Options

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();
```

### Qwen Options

#### Image Generation

Qwen provides image generation through `qwen-image-max` and `qwen-image-plus` models:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-max')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1328*1328',                         // Image resolution (width*height)
        'negative_prompt' => 'low quality, blurry',    // Content to avoid
        'prompt_extend' => true,                       // Enable prompt rewriting
        'watermark' => false,                          // Disable watermark
        'seed' => 42,                                  // Random seed for reproducibility
    ])
    ->generate();
```

> [!IMPORTANT]
> Generated image URLs are valid for **24 hours** only. Download and save images promptly.

#### Image Editing

Qwen's `qwen-image-edit-max` and `qwen-image-edit-plus` models support single-image editing and multi-image fusion. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Generate an image following this depth map: a red bicycle on a muddy path with dense forest', [
        Image::fromUrl('https://example.com/depth-map.png'),
    ])
    ->withProviderOptions([
        'n' => 2,                              // Output 1-6 images (max/plus models)
        'size' => '1536*1024',                 // Custom output resolution
        'negative_prompt' => 'low quality',    // Content to avoid
        'prompt_extend' => true,               // Enable prompt rewriting
        'watermark' => false,                  // Disable watermark
    ])
    ->generate();
```

For multi-image fusion, pass multiple images — the model can combine elements from up to 3 input images:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('The girl in image 1 wearing the black dress from image 2, sitting in the pose of image 3', [
        Image::fromUrl('https://example.com/person.png'),
        Image::fromUrl('https://example.com/dress.png'),
        Image::fromUrl('https://example.com/pose.png'),
    ])
    ->withProviderOptions([
        'n' => 2,
        'size' => '1024*1536',
    ])
    ->generate();
```

You can also pass images from local files or base64 data:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Add a sunset sky to the background', [
        Image::fromLocalPath('/path/to/photo.png'),
    ])
    ->generate();
```

## Testing

Prism provides convenient fakes for testing image generation:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;

test('can generate images', function () {
    $fake = PrismFake::create()->image();
    Prism::fake($fake);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->withPrompt('Test image')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->firstImage()->url)->toContain('fake-image-url');
});
```

Need help with a specific provider or use case? Check the [openai documentation](/providers/openai), [gemini documentation](/providers/gemini), or [qwen documentation](/providers/qwen) for detailed configuration options and examples.
