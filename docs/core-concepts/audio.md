# Audio Processing

Transform text into speech and speech into text using AI-powered audio models. Prism provides a unified API for audio processing across different providers, enabling both text-to-speech (TTS) and speech-to-text (STT) functionality.

## Getting Started

### Text-to-Speech

Convert text into natural-sounding speech:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::audio()
    ->using(Provider::OpenAI, 'tts-1')
    ->withInput('Hello, this is a test of text-to-speech functionality.')
    ->withVoice('alloy')
    ->asAudio();

$audio = $response->audio;
if ($audio->hasBase64()) {
    file_put_contents('output.mp3', base64_decode($audio->base64));
    echo "Audio saved as: output.mp3";
}
```

### Speech-to-Text

Convert audio files into text transcriptions:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromPath('/path/to/audio.mp3');

$response = Prism::audio()
    ->using(Provider::OpenAI, 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

## Provider Support

Currently, Prism supports audio processing through:

- **OpenAI**: TTS-1, TTS-1-HD (text-to-speech) and Whisper-1 (speech-to-text)
- **Groq**: PlayAI TTS models (text-to-speech) and Whisper Large V3 models (speech-to-text)

Additional providers will be added in future releases as the ecosystem evolves.

## Basic Usage

### Simple Text-to-Speech

Generate speech from text input:

```php
$response = Prism::audio()
    ->using('openai', 'tts-1')
    ->withInput('Welcome to our application!')
    ->withVoice('alloy')
    ->asAudio();

$audio = $response->audio;
echo "Audio type: " . $audio->getMimeType(); // audio/mpeg
echo "Has audio data: " . ($audio->hasBase64() ? 'Yes' : 'No');
```

### Simple Speech-to-Text

Transcribe audio files to text:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From file path
$audioFile = Audio::fromPath('/path/to/recording.wav');

// From a URL you trust — fetched explicitly, see below
$audioFile = Audio::fromUrl('https://example.com/audio.mp3')->fetchUrlContent();

// From base64 data
$audioFile = Audio::fromBase64($base64AudioData, 'audio/wav');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Access the transcription
echo $response->text;
```

::: warning URLs are not fetched automatically
Audio built with `fromUrl()` has no bytes until you call `fetchUrlContent()`, which downloads the file from your server. Only call it on a URL you control or have validated. Never call it on a URL taken from user input — that lets anyone make your server request an address of their choosing, including internal services.

For audio a user uploads, use `fromBase64()` or `fromLocalPath()` on the stored file instead.
:::

## Working with Audio Files

### Creating Audio Objects

The `Audio` class provides several ways to work with audio files:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file
$audio = Audio::fromPath('/path/to/audio.mp3');

// From remote URL
$audio = Audio::fromUrl('https://example.com/speech.wav');

// From base64 encoded data
$audio = Audio::fromBase64($base64Data, 'audio/mpeg');

// From raw binary content
$audio = Audio::fromContent($binaryData, 'audio/wav');
```

### Audio Properties

Access audio file information:

```php
$audio = Audio::fromPath('/path/to/audio.mp3');

echo "MIME type: " . $audio->mimeType();
echo "Has local path: " . ($audio->hasLocalPath() ? 'Yes' : 'No');
echo "File size: " . $audio->size() . " bytes";
```

## Working with Responses

### Text-to-Speech Responses

```php
$response = Prism::audio()
    ->using('openai', 'tts-1-hd')
    ->withInput('This is high-quality text-to-speech.')
    ->withVoice('nova')
    ->asAudio();

// Access the generated audio
$audio = $response->audio;

if ($audio->hasBase64()) {
    // Save to file
    $audioData = base64_decode($audio->base64);
    file_put_contents('speech.mp3', $audioData);
    
    // Get MIME type
    echo "Content type: " . $audio->getMimeType();
}

// Access additional response data
foreach ($response->additionalContent as $key => $value) {
    echo "{$key}: {$value}\n";
}
```

### Speech-to-Text Responses

```php
$audioFile = Audio::fromPath('/path/to/speech.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Access the transcription
$text = $response->text;
echo "Transcription: " . $text;

// Check token usage
if ($response->usage) {
    echo "Prompt tokens: " . $response->usage->promptTokens;
    echo "Completion tokens: " . $response->usage->completionTokens;
}

// Access raw response data
print_r($response->additionalContent);
```

## Voice Selection

Prism provides a dedicated `withVoice()` method for selecting voices in text-to-speech, making voice selection a first-class citizen in the API:

```php
$response = Prism::audio()
    ->using('openai', 'tts-1')
    ->withInput('Hello, how are you today?')
    ->withVoice('alloy')  // Voice options vary by provider
    ->asAudio();
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Text-to-Speech Options

Customize format, speed, and other options:

```php
$response = Prism::audio()
    ->using('openai', 'tts-1')
    ->withInput('Hello, how are you today?')
    ->withVoice('nova')
    ->withProviderOptions([
        'response_format' => 'mp3',           // mp3, opus, aac, flac, wav, pcm
        'speed' => 1.0,                       // 0.25 to 4.0
    ])
    ->asAudio();
```

### OpenAI Speech-to-Text Options

Configure transcription settings:

```php
$audioFile = Audio::fromPath('/path/to/multilingual.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'en',
        'prompt' => 'Previous context...'
    ])
    ->asText();
```

### Response Formats

Different response formats provide varying levels of detail:

```php
// Verbose JSON format includes timestamps and confidence scores
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'verbose_json',
    ])
    ->asText();

// Access additional metadata
$metadata = $response->additionalContent;
if (isset($metadata['segments'])) {
    foreach ($metadata['segments'] as $segment) {
        echo "Segment: " . $segment['text'] . "\n";
        echo "Start: " . $segment['start'] . "s\n";
        echo "End: " . $segment['end'] . "s\n";
    }
}
```

## Advanced Usage

Audio can be integrated into multi-modal conversations:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Text;

$audioFile = Audio::fromPath('/path/to/question.mp3');

// First transcribe the audio
$transcription = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Then use in a text conversation
$response = Prism::text()
    ->using('openai', 'gpt-4')
    ->withMessages([
        new UserMessage('', [
            new Text('User asked: '),
            new Text($transcription->text),
            new Text(' - Please provide a detailed response.')
        ])
    ])
    ->asText();

// Convert response back to speech
$speechResponse = Prism::audio()
    ->using('openai', 'tts-1')
    ->withInput($response->text)
    ->withVoice('alloy')
    ->asAudio();
```

## Configuration Options

### Client Configuration

Configure HTTP client options for audio processing:

```php
$response = Prism::audio()
    ->using('openai', 'tts-1')
    ->withInput('This might take a while to process.')
    ->withClientOptions([
        'timeout' => 60,           // Increase timeout for large files
        'connect_timeout' => 10,   // Connection timeout
    ])
    ->withClientRetry(3, 1000)     // Retry 3 times with 1s delay
    ->asAudio();
```

### Provider Configuration

Override provider configuration for multi-tenant applications:

```php
$customConfig = [
    'api_key' => 'user-specific-api-key',
    'organization' => 'user-org-id',
];

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->usingProviderConfig($customConfig)
    ->withInput($audioFile)
    ->asText();
```

## Error Handling

Handle potential errors in audio processing:

```php
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::audio()
        ->using('openai', 'tts-1')
        ->withInput('Text to convert to speech')
        ->withVoice('alloy')
        ->asAudio();
        
    // Process successful response
    file_put_contents('output.mp3', base64_decode($response->audio->base64));
    
} catch (PrismException $e) {
    Log::error('Audio processing failed:', ['error' => $e->getMessage()]);
    // Handle Prism-specific errors
} catch (Throwable $e) {
    Log::error('General error:', ['error' => $e->getMessage()]);
    // Handle any other errors
}
```

## Testing

Prism provides convenient fakes for testing audio functionality:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\ValueObjects\GeneratedAudio;

test('can generate text-to-speech', function () {
    $fakeAudio = new AudioResponse(
        audio: new GeneratedAudio(
            base64: base64_encode('fake-audio-data'),
            type: 'audio/mpeg'
        )
    );
    
    Prism::fake([$fakeAudio]);

    $response = Prism::audio()
        ->using('openai', 'tts-1')
        ->withInput('Test audio generation')
        ->withVoice('alloy')
        ->asAudio();

    expect($response->audio->hasBase64())->toBeTrue();
    expect($response->audio->getMimeType())->toBe('audio/mpeg');
});

test('can transcribe speech-to-text', function () {
    $fakeTranscription = new TextResponse(
        text: 'This is a fake transcription'
    );
    
    Prism::fake([$fakeTranscription]);

    $audioFile = Audio::fromPath('/fake/path/test.mp3');
    
    $response = Prism::audio()
        ->using('openai', 'whisper-1')
        ->withInput($audioFile)
        ->asText();

    expect($response->text)->toBe('This is a fake transcription');
});
```

