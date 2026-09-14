<script setup>
import ProviderSupport from '../components/ProviderSupport.vue'
</script>

# Introduction

Large Language Models (LLMs) have revolutionized how we interact with artificial intelligence, enabling applications to understand, generate, and manipulate human language with unprecedented sophistication. These powerful models open up exciting possibilities for developers, from creating chatbots and content generators to building complex AI-driven applications.

Prism **simplifies the process of integrating LLMs into your Laravel projects**, providing a unified interface to work with various AI providers. This allows you to focus on crafting innovative AI features for your users, rather than getting bogged down in the intricacies of different APIs and implementation details.

Here's a quick example of how you can generate text using Prism:

::: code-group
```php [Anthropic]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Mistral]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Mistral, 'mistral-medium')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Ollama]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'llama2')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [OpenAI]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Replicate]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Replicate, 'meta/meta-llama-3.1-405b-instruct')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```
:::

Prism draws significant inspiration from the [Vercel AI SDK](https://sdk.vercel.ai/docs/ai-sdk-core), adapting its powerful concepts and developer-friendly approach to the Laravel ecosystem.

## Key Features

- **Unified Provider Interface**: Switch seamlessly between AI providers like OpenAI, Anthropic, and Ollama without changing your application code.
- **Tool System**: Extend AI capabilities by defining custom tools that can interact with your application's business logic.
- **Image Support**: Work with multi-modal models that can process both text and images.

Prism also provides a fluent `prism()` helper function to resolve the `Prism` instance from the application container.

```php
prism()
    ->text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();
``` 

## Providers

We currently offer first-party support for these leading AI providers:

- [Anthropic](/providers/anthropic.md)
- [DeepSeek](/providers/deepseek.md)
- [Groq](/providers/groq.md)
- [Mistral](/providers/mistral.md)
- [Ollama](/providers/ollama.md)
- [OpenAI](/providers/openai.md)
- [Replicate](/providers/replicate.md)
- [Qwen](/providers/qwen.md)
- [xAI](/providers/xai.md)
- [Perplexity](/providers/perplexity.md)

Each provider brings its own strengths to the table, and Prism makes it easy to use them all through a consistent, elegant interface.

## Provider Support

Make sure you check the dedicated provider pages for considerations, limitations, and options. Support may be model dependant, check with your provider for model specific features and support.

<ProviderSupport />
