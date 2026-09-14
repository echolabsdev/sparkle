# Qwen

Alibaba Cloud's Qwen models are available through the [DashScope (Model Studio)](https://www.alibabacloud.com/help/en/model-studio/) API. Prism uses the **DashScope native API** (not the OpenAI-compatible mode) for all Qwen interactions, providing the most complete and up-to-date feature support.

> [!NOTE]
> This provider uses DashScope's native API (`/api/v1`) endpoints. While DashScope also offers an OpenAI-compatible interface (`/compatible-mode/v1`), the native API provides better feature coverage, more active maintenance from Alibaba Cloud, and a consistent experience across all capabilities (text, embeddings, images).

## Configuration

```php
'qwen' => [
    'api_key' => env('QWEN_API_KEY', ''),
    'url' => env('QWEN_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'),
]
```

### Deployment Modes & Regions

DashScope offers multiple [deployment modes](https://www.alibabacloud.com/help/en/model-studio/regions/), each with its own **base URL**, **API key**, and **available models**. You must configure the correct combination for your region:

| Deployment Mode | Region | `QWEN_URL` |
|----------------|--------|------------|
| **International** | Singapore | `https://dashscope-intl.aliyuncs.com/api/v1` (default) |
| **China Mainland** | Beijing | `https://dashscope.aliyuncs.com/api/v1` |
| **Global** | Virginia (US) | `https://dashscope-us.aliyuncs.com/api/v1` |
| **United States** | Virginia (US) | `https://dashscope-us.aliyuncs.com/api/v1` |

> [!IMPORTANT]
> **API keys and base URLs are region-specific and cannot be mixed across regions.** A Beijing API key will not work with the Singapore endpoint, and vice versa. Make sure your `QWEN_API_KEY` and `QWEN_URL` belong to the same deployment mode.

> [!WARNING]
> **Model availability varies by deployment mode.** Not all models are available in every region. Some models may have a `-us` suffix in the US deployment mode. Always check the [official model list](https://www.alibabacloud.com/help/en/model-studio/models) to confirm model availability in your region before use.

### Getting an API Key

1. Sign up or log in to [Alibaba Cloud Model Studio](https://www.alibabacloud.com/help/en/model-studio/get-api-key)
2. Select the region matching your deployment mode
3. Create an API key in the **Key Management** page

```env
QWEN_API_KEY=sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
# Uncomment and change if not using International (Singapore):
# QWEN_URL=https://dashscope.aliyuncs.com/api/v1
```

## Text Generation

### Basic Usage

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

echo $response->text;
```

### With System Prompt

```php
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withSystemPrompt('You are a helpful coding assistant.')
    ->withPrompt('How do I implement a binary search in PHP?')
    ->asText();
```

### Tool Calling

Qwen supports function calling with tools, allowing the model to request external data during generation:

```php
use Prism\Prism\Facades\Tool;

$tools = [
    Tool::as('get_weather')
        ->for('Get current weather conditions for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "72°F and sunny in {$city}"),

    Tool::as('search')
        ->for('Search for current events or data')
        ->withStringParameter('query', 'The search query')
        ->using(fn (string $query): string => "Results for: {$query}"),
];

$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withTools($tools)
    ->withMaxSteps(4)
    ->withPrompt('What is the weather in Detroit and when is the Tigers game?')
    ->asText();
```

## Multi-Modal (Vision) Text Generation

Qwen offers vision-language (VL) models like `qwen-vl-max` and `qwen-vl-plus` that can understand and analyze images alongside text. Simply include images in your messages — Prism automatically routes to the correct DashScope multimodal endpoint:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-vl-max')
    ->withPrompt('What objects do you see in this image?', [
        Image::fromUrl('https://example.com/photo.jpeg'),
    ])
    ->asText();

echo $response->text;
```

### Multi-Image Analysis

Qwen VL models support analyzing multiple images simultaneously:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-vl-max')
    ->withMessages([
        new UserMessage('What are these animals?', [
            Image::fromUrl('https://example.com/dog.jpeg'),
            Image::fromUrl('https://example.com/tiger.png'),
            Image::fromUrl('https://example.com/rabbit.png'),
        ]),
    ])
    ->asText();
```

### Supported Input Formats

Images can be provided via URL, local file, or base64:

```php
// From URL
Image::fromUrl('https://example.com/photo.jpeg')

// From local file
Image::fromLocalPath('/path/to/image.png')

// From base64
Image::fromBase64($base64Data, 'image/png')
```

### Available VL Models

| Model | Description |
|-------|-------------|
| `qwen-vl-max` | Most capable vision model |
| `qwen-vl-plus` | Balanced vision model |

> [!NOTE]
> When images are present in messages, Prism automatically routes the request to DashScope's `multimodal-generation` endpoint instead of `text-generation`. This is transparent — you don't need to configure anything differently.

> [!TIP]
> VL model availability varies by region. Check the [official model list](https://www.alibabacloud.com/help/en/model-studio/models) for your deployment mode.

## Structured Output

Qwen supports structured output through DashScope's native `response_format` parameter, with two modes:

- **JSON Object mode** (default): Ensures the output is valid JSON. Broadly supported by most Qwen models.
- **JSON Schema mode**: Strictly enforces schema structure and types. Supported by `qwen3-max`, `qwen-plus`, `qwen-flash` series and later models.

### JSON Object Mode (Default)

By default, Prism uses JSON Object mode with a system message containing the schema definition to guide the model:

```php
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    'weather_report',
    'Weather report with recommendations',
    [
        new StringSchema('weather', 'The weather forecast'),
        new StringSchema('game_time', 'The game time'),
        new BooleanSchema('coat_required', 'Whether a coat is needed'),
    ],
    ['weather', 'game_time', 'coat_required']
);

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::Qwen, 'qwen-plus')
    ->withSystemPrompt('The Tigers game is at 3pm in Detroit, temperature is 75°F')
    ->withPrompt('What time is the game and should I wear a coat?')
    ->asStructured();

echo $response->structured['weather'];       // "75°F"
echo $response->structured['coat_required'];  // false
```

### JSON Schema Mode (Strict)

For models that support it, JSON Schema mode provides strict schema enforcement — the model is guaranteed to return output that conforms exactly to your schema:

```php
use Prism\Prism\Enums\StructuredMode;

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::Qwen, 'qwen-plus')
    ->usingStructuredMode(StructuredMode::Structured)
    ->withPrompt('What time is the game and should I wear a coat?')
    ->asStructured();
```

> [!NOTE]
> JSON Schema mode is supported by: `qwen3-max` series, `qwen-plus` series (qwen-plus-2025-07-28 and later), and `qwen-flash` series (qwen-flash-2025-07-28 and later). Check the [official documentation](https://www.alibabacloud.com/help/en/model-studio/qwen-structured-output) for the full list. Thinking mode models do not support structured output.

## Streaming

Qwen supports streaming responses in real-time via DashScope's SSE (Server-Sent Events) protocol. Prism handles the DashScope-specific SSE format (with `X-DashScope-SSE` header and `incremental_output` mode) transparently:

```php
$stream = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withPrompt('Write a short story about a robot')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
        echo $event->delta;
    }
}
```

### Server-Sent Events

```php
return Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Tools

Streaming works seamlessly with tool calling. The stream will emit tool call and tool result events during multi-step interactions:

```php
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withTools($tools)
    ->withMaxSteps(4)
    ->withPrompt('What is the weather in Detroit?')
    ->asStream();

foreach ($response as $event) {
    match (true) {
        $event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent => echo $event->delta,
        $event instanceof \Prism\Prism\Streaming\Events\ToolCallEvent => echo "Calling: {$event->toolCall->name}\n",
        $event instanceof \Prism\Prism\Streaming\Events\ToolResultEvent => echo "Result received\n",
        default => null,
    };
}
```

### Streaming with Reasoning (Thinking Models)

Qwen's thinking-capable models like `qwq-plus` stream their reasoning process separately from the final answer through the `reasoning_content` field:

```php
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::Qwen, 'qwq-plus')
    ->withPrompt('Solve this step by step: What is 15% of 240?')
    ->asStream();

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta . "\n",
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

> [!NOTE]
> Reasoning/thinking tokens are only available with thinking-capable models such as `qwq-plus`. Standard models like `qwen-plus` do not produce reasoning content.

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Embeddings

Qwen provides text embedding capabilities through models like `text-embedding-v4`:

```php
$response = Prism::embeddings()
    ->using(Provider::Qwen, 'text-embedding-v4')
    ->fromInput('Hello, how are you?')
    ->asEmbeddings();

$embedding = $response->embeddings[0]->embedding; // Array of floats
```

### Custom Dimensions

You can control the output dimensionality of embeddings using the `dimensions` provider option:

```php
$response = Prism::embeddings()
    ->using(Provider::Qwen, 'text-embedding-v4')
    ->withProviderOptions([
        'dimensions' => 512,
    ])
    ->fromInput('Hello, how are you?')
    ->asEmbeddings();
```

## Audio Processing (TTS / STT)

> [!CAUTION]
> Audio processing is **not supported** through the Qwen provider. DashScope provides TTS via [CosyVoice](https://www.alibabacloud.com/help/en/model-studio/cosyvoice-websocket-api) (WebSocket-only, Beijing region only) and STT via [Paraformer](https://www.alibabacloud.com/help/en/model-studio/paraformer-recorded-speech-recognition-restful-api) (asynchronous REST API requiring task submission and polling).
>
> These use entirely different protocols that are not compatible with Prism's synchronous HTTP interface. For audio processing, use the official [DashScope SDK](https://www.alibabacloud.com/help/en/model-studio/developer-reference/sdk-reference) instead.

## Image Generation

Qwen provides image generation through models like `qwen-image-max` and `qwen-image-plus`, using the DashScope native multimodal generation endpoint:

> [!NOTE]
> Image model availability varies by region. Check the [official model list](https://www.alibabacloud.com/help/en/model-studio/models) to confirm which models are available in your deployment mode.

### Basic Usage

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-max')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->generate();

$imageUrl = $response->firstImage()->url;
```

> [!IMPORTANT]
> Generated image URLs are valid for **24 hours** only. Download and save images promptly.

### Generation Models

| Model | Description |
|-------|-------------|
| `qwen-image-max` | Enhanced realism and naturalness, excellent text rendering |
| `qwen-image-plus` | Diverse artistic styles, strong at complex text rendering |

### With Provider Options

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-max')
    ->withPrompt('A sunset over mountain peaks')
    ->withProviderOptions([
        'size' => '1328*1328',          // Image resolution (width*height)
        'negative_prompt' => 'low quality, blurry',  // Content to avoid
        'prompt_extend' => true,         // Enable prompt rewriting
        'watermark' => false,            // Disable watermark
        'seed' => 42,                    // Random seed for reproducibility
    ])
    ->generate();
```

### Available Sizes (Generation)

| Size | Aspect Ratio |
|------|-------------|
| `1664*928` (default) | 16:9 |
| `1472*1104` | 4:3 |
| `1328*1328` | 1:1 |
| `1104*1472` | 3:4 |
| `928*1664` | 9:16 |

## Image Editing

Qwen's image editing models support single-image editing and multi-image fusion — you can precisely modify text, add/remove/move objects, change poses, transfer styles, and enhance details. Pass input images as the second parameter to `withPrompt()`:

> [!TIP]
> For complete API documentation, see [Qwen-Image-Edit API Reference](https://www.alibabacloud.com/help/en/model-studio/qwen-image-edit-api).

### Single Image Editing

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

### Multi-Image Fusion

The model can combine elements from up to 3 input images. Images are referenced by their order in the array (image 1, image 2, image 3):

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

### Editing from Local Files

You can also pass images from local files or base64 data:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Add a sunset sky to the background', [
        Image::fromLocalPath('/path/to/photo.png'),
    ])
    ->generate();
```

### Editing Models

| Model | Description | Output Images |
|-------|-------------|--------------|
| `qwen-image-edit-max` | Single-image editing & multi-image fusion, custom resolution | 1-6 |
| `qwen-image-edit-plus` | Single-image editing & multi-image fusion, custom resolution | 1-6 |
| `qwen-image-edit` | Single-image editing & multi-image fusion, auto resolution | 1 |

### Available Sizes (Editing)

Width and height must each be between 512 and 2048 pixels. Common recommended resolutions:

| Size | Aspect Ratio |
|------|-------------|
| `1024*1024`, `1536*1536` | 1:1 |
| `768*1152`, `1024*1536` | 2:3 |
| `1152*768`, `1536*1024` | 3:2 |
| `960*1280`, `1080*1440` | 3:4 |
| `1280*960`, `1440*1080` | 4:3 |
| `720*1280`, `1080*1920` | 9:16 |
| `1280*720`, `1920*1080` | 16:9 |

> [!NOTE]
> If `size` is not specified, the output image will have a similar aspect ratio to the input image (last image when multiple inputs), with total pixels close to 1024×1024.

### Response Details

The response includes additional content with image dimensions:

```php
$response->additionalContent['image_count']; // Number of images
$response->additionalContent['width'];       // Image width in pixels
$response->additionalContent['height'];      // Image height in pixels
$response->meta->id;                         // DashScope request ID
```

For complete image generation documentation, see [Image Generation](/core-concepts/image-generation).

## Limitations

### Tool Choice

Qwen's `tool_choice` parameter only supports `"auto"` and `"none"`. Forcing a specific tool by name is not supported and will throw an `InvalidArgumentException`:

```php
// This works
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withTools($tools)
    ->withToolChoice(\Prism\Prism\Enums\ToolChoice::Auto)
    ->withPrompt('...')
    ->asText();

// This will throw an InvalidArgumentException
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-plus')
    ->withTools($tools)
    ->withToolChoice('specific_tool_name')
    ->withPrompt('...')
    ->asText();
```

### Images in Messages

Qwen supports images in user messages via URL, local file, and base64 encoding. When images are present, Prism automatically routes the request to DashScope's multimodal endpoint using the native content format (`{"image": "url"}`, `{"text": "text"}`). See [Multi-Modal (Vision) Text Generation](#multi-modal-vision-text-generation) for details.

### Moderation

Content moderation is not currently supported as a standalone feature through the Qwen provider.

## Error Handling

Qwen has a few provider-specific error codes that Prism handles automatically:

| Error | Description |
|-------|-------------|
| `Arrearage` | Your Alibaba Cloud account has an unpaid balance |
| `DataInspectionFailed` | Content moderation rejected the request |
| `429` | Rate limit exceeded (throws `PrismRateLimitedException`) |
| `503` | Service overloaded (throws `PrismProviderOverloadedException`) |

DashScope native API errors return a top-level `code` and `message` field in the response body (as opposed to the nested `error` object in OpenAI-compatible mode). Prism handles both formats transparently.

All other errors are handled through the standard Prism error handling flow. For more details, see [Error Handling](/advanced/error-handling).
