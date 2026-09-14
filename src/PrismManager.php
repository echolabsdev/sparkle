<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\DeepSeek\DeepSeek;
use Prism\Prism\Providers\ElevenLabs\ElevenLabs;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\Providers\Groq\Groq;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Ollama\Ollama;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Providers\OpenRouter\OpenRouter;
use Prism\Prism\Providers\Perplexity\Perplexity;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Qwen\Qwen;
use Prism\Prism\Providers\Replicate\Replicate;
use Prism\Prism\Providers\Requesty\Requesty;
use Prism\Prism\Providers\Vertex\Vertex;
use Prism\Prism\Providers\VoyageAI\VoyageAI;
use Prism\Prism\Providers\XAI\XAI;
use Prism\Prism\Providers\Z\Z;

class PrismManager
{
    /** @var array<string, Closure> */
    protected array $customCreators = [];

    public function __construct(
        protected Application $app
    ) {}

    /**
     * @param  array<string, mixed>  $providerConfig
     *
     * @throws InvalidArgumentException
     */
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        $name = $this->resolveName($name);

        $config = array_merge($this->getConfig($name), $providerConfig);

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name, $config);
        }

        $factory = sprintf('create%sProvider', ucfirst($name));

        if (method_exists($this, $factory)) {
            return $this->{$factory}($config);
        }

        throw new InvalidArgumentException("Provider [{$name}] is not supported.");
    }

    public function extend(string $provider, Closure $callback): self
    {
        $this->customCreators[$provider] = $callback;

        return $this;
    }

    protected function resolveName(ProviderEnum|string $name): string
    {
        if ($name instanceof ProviderEnum) {
            $name = $name->value;
        }

        return strtolower($name);
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createOpenaiProvider(array $config): OpenAI
    {
        return new OpenAI(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
            organization: $config['organization'] ?? null,
            project: $config['project'] ?? null,
            apiFormat: $config['api_format'] ?? 'responses',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createOllamaProvider(array $config): Ollama
    {
        return new Ollama(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createMistralProvider(array $config): Mistral
    {
        return new Mistral(
            apiKey: $config['api_key'],
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createAnthropicProvider(array $config): Anthropic
    {
        return new Anthropic(
            apiKey: $config['api_key'],
            apiVersion: $config['version'],
            url: $config['url'] ?? 'https://api.anthropic.com/v1',
            betaFeatures: $config['anthropic_beta'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createDeepseekProvider(array $config): DeepSeek
    {
        return new DeepSeek(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? '',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createVoyageaiProvider(array $config): VoyageAI
    {
        return new VoyageAI(
            apiKey: $config['api_key'] ?? '',
            baseUrl: $config['url'] ?? ''
        );
    }

    /**
     * @param  array<string, string|null>  $config
     */
    protected function createPerplexityProvider(array $config): Perplexity
    {
        return new Perplexity(apiKey: $config['api_key'] ?? '', url: $config['url'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callCustomCreator(string $provider, array $config): Provider
    {
        return $this->customCreators[$provider]($this->app, $config);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return config("prism.providers.{$name}", []);
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createGroqProvider(array $config): Groq
    {
        return new Groq(
            apiKey: $config['api_key'],
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createXaiProvider(array $config): XAI
    {
        return new XAI(
            apiKey: $config['api_key'],
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createGeminiProvider(array $config): Gemini
    {
        return new Gemini(
            apiKey: $config['api_key'],
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOpenrouterProvider(array $config): OpenRouter
    {
        $siteConfig = $config['site'] ?? null;
        $site = is_array($siteConfig) ? $siteConfig : [];

        return new OpenRouter(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://openrouter.ai/api/v1',
            httpReferer: $site['http_referer'] ?? null,
            xTitle: $site['x_title'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createRequestyProvider(array $config): Requesty
    {
        $siteConfig = $config['site'] ?? null;
        $site = is_array($siteConfig) ? $siteConfig : [];

        return new Requesty(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://router.requesty.ai/v1',
            httpReferer: $site['http_referer'] ?? null,
            xTitle: $site['x_title'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createAzureProvider(array $config): Azure
    {
        return new Azure(
            url: $config['url'] ?? '',
            apiKey: $config['api_key'] ?? '',
            apiVersion: $config['api_version'] ?? '2024-10-21',
            deploymentName: $config['deployment_name'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createElevenlabsProvider(array $config): ElevenLabs
    {
        return new ElevenLabs(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://api.elevenlabs.io/v1/',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createReplicateProvider(array $config): Replicate
    {
        return new Replicate(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://api.replicate.com/v1',
            webhookUrl: $config['webhook_url'] ?? null,
            useSyncMode: $config['use_sync_mode'] ?? true,
            pollingInterval: $config['polling_interval'] ?? 1000,
            maxWaitTime: $config['max_wait_time'] ?? 60,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createQwenProvider(array $config): Qwen
    {
        return new Qwen(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://dashscope-intl.aliyuncs.com/api/v1',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createVertexProvider(array $config): Vertex
    {
        return new Vertex(
            projectId: $config['project_id'] ?? '',
            region: $config['region'] ?? 'us-central1',
            accessToken: $config['access_token'] ?? null,
            credentialsPath: $config['credentials_path'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createZProvider(array $config): Z
    {
        return new Z(
            apiKey: $config['api_key'],
            url: $config['url'],
        );
    }
}
