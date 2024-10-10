<?php

declare(strict_types=1);

namespace EchoLabs\Prism;

use Closure;
use EchoLabs\Prism\Contracts\Driver;
use EchoLabs\Prism\Drivers\Anthropic\Anthropic;
use EchoLabs\Prism\Drivers\Google\Google;
use EchoLabs\Prism\Drivers\OpenAI\OpenAI;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use RuntimeException;

class PrismManager
{
    /** @var array<string, Closure> */
    protected $customCreators = [];

    public function __construct(
        protected Application $app
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function resolve(string $name): Driver
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Provider [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst((string) $config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createOpenaiDriver(array $config): OpenAI
    {
        return new OpenAI(
            $config['api_key'] ?? '',
            $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createAnthropicDriver(array $config): Anthropic
    {
        return new Anthropic(
            $config['api_key'],
            $config['version'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createGoogleDriver(array $config): Google
    {
        return new Google(
            $config['base_url'],
            $config['api_key'],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callCustomCreator(array $config): Driver
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * @throws RuntimeException
     */
    public function extend(string $driver, Closure $callback): self
    {
        if (($callback = $callback->bindTo($this, $this)) instanceof \Closure) {
            $this->customCreators[$driver] = $callback;

            return $this;
        }

        throw new RuntimeException(
            sprintf('Couldn\'t bind %s', $driver)
        );
    }

    /**
     * @return null|array<string, mixed>
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== '' && $name !== '0') {
            return config("prism.providers.{$name}");
        }

        return ['driver' => 'null'];
    }
}
