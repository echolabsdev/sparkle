<?php

declare(strict_types=1);

namespace Prism\Prism\Tools;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Support\ValidationMessages;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

class LaravelMcpTool extends Tool
{
    public function __construct(private readonly \Laravel\Mcp\Server\Tool $tool)
    {
        $this->as($tool->name())
            ->for($tool->description());

        $data = $tool->toArray();
        $properties = $data['inputSchema']['properties'] ?? [];
        $required = $data['inputSchema']['required'] ?? [];

        foreach ($properties as $name => $property) {
            $this->withParameter(new RawSchema($name, $property), in_array($name, $required, true));
        }
    }

    /**
     * @phpstan-ignore missingType.parameter
     */
    public function __invoke(...$args): string
    {
        // Set default values for parameters that are not provided
        $properties = $this->parametersAsArray();
        foreach ($properties as $name => $property) {
            if (! isset($args[$name]) && isset($property['default'])) {
                $args[$name] = $property['default'];
            }
        }

        $request = new Request($args);

        try {
            /**
             * @var Response|ResponseFactory|\Generator<Response> $response
             *
             * @phpstan-ignore method.notFound
             */
            $response = $this->tool->handle($request);
        } catch (ValidationException $validationException) {
            $response = Response::error(ValidationMessages::from($validationException));
        }

        if ($response instanceof ResponseFactory) {
            return $response->responses()
                ->map(fn (Response $response): string => $response->content()->__toString())
                ->implode("\n");
        }

        if (is_iterable($response)) {
            return collect(iterator_to_array($response))
                ->map(fn (Response $response): string => $response->content()->__toString())
                ->implode("\n");
        }

        return $response->content()->__toString();
    }
}
