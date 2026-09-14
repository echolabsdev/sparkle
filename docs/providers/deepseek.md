# DeepSeek
## Configuration

```php
'deepseek' => [
    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')
]
```

## Provider-specific options

## Streaming

DeepSeek supports streaming responses in real-time. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('deepseek', 'deepseek-v4-flash')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Limitations
### Embeddings

Does not support embeddings.

### Tool Choice

Does not support tool choice.

### Images

Does not support images.
