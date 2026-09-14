<?php

declare(strict_types=1);

namespace Prism\Prism\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Prism\Prism\Tool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:prism-tool` — scaffolds a class-based {@see Tool}.
 *
 * NOT `make:mcp-tool`, which `laravel/mcp` already owns and which generates
 * something else entirely: a tool your application EXPOSES over MCP. This
 * generates a tool you hand to a model through `withTools()`. The two point in
 * opposite directions across the protocol and the distinction is recorded in
 * prism-parity decision 0018.
 *
 * The generated shape is the one Prism actually resolves: `__construct()`
 * configures the tool, `__invoke()` handles the call. `Tool::resolveHandler()`
 * falls back to `$this` when no `using()` closure was set and the class is
 * invokable, which is why a subclass needs no `->using(...)` line.
 */
#[AsCommand(
    name: 'make:prism-tool',
    description: 'Create a new Prism tool class'
)]
class MakeToolCommand extends GeneratorCommand
{
    /**
     * @var string
     */
    protected $type = 'Tool';

    /** @var list<array{name: string, php: string, call: string, required: bool}>|null */
    protected ?array $parsed = null;

    /**
     * Parameters are parsed BEFORE the parent writes anything.
     *
     * A malformed `--parameter` discovered during `buildClass()` would leave a
     * half-written class on disk that the developer then has to notice and
     * delete. Failing first means a typo costs a message and nothing else.
     */
    public function handle(): ?bool
    {
        try {
            $this->parsed = $this->parameters();
        } catch (InvalidArgumentException $e) {
            // fail() rather than `return false`. Laravel casts a falsy handle()
            // return to exit code 0, so a generator that printed the error and
            // returned false would still tell CI it had succeeded. fail()
            // throws, and Command::execute turns that into a real exit code.
            $this->fail($e->getMessage());
        }

        return parent::handle();
    }

    protected function getStub(): string
    {
        return file_exists($customPath = $this->laravel->basePath('stubs/prism-tool.stub'))
            ? $customPath
            : __DIR__.'/../../../stubs/prism-tool.stub';
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return "{$rootNamespace}\\Tools";
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    protected function getOptions(): array
    {
        return [
            ['description', 'd', InputOption::VALUE_REQUIRED, 'What the tool is for. The model reads this to decide whether to call it'],
            ['parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A parameter as name:type[:description]. Repeatable. Types: string, number, integer, boolean, enum(a,b,c). Suffix the type with ? to make it optional'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the tool already exists'],
        ];
    }

    /**
     * @param  string  $name
     *
     * @throws FileNotFoundException
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $parameters = $this->parsed ?? $this->parameters();

        return str_replace(
            ['{{ toolName }}', '{{ description }}', '{{ parameters }}', '{{ signature }}'],
            [
                $this->toolName($name),
                $this->quote($this->describedAs($name)),
                $this->builderCalls($parameters),
                $this->handlerSignature($parameters),
            ],
            $stub,
        );
    }

    /**
     * The name the MODEL sees, which is not the class name.
     *
     * `SearchTool` becomes `search`, not `SearchTool` — providers expect
     * snake_case identifiers, and a trailing "Tool" is a PHP naming convention
     * that means nothing to a model and wastes tokens in every request.
     */
    protected function toolName(string $name): string
    {
        $class = class_basename($name);

        return Str::snake(Str::endsWith($class, 'Tool') && $class !== 'Tool'
            ? Str::beforeLast($class, 'Tool')
            : $class);
    }

    protected function describedAs(string $name): string
    {
        $description = $this->option('description');

        if (is_string($description) && $description !== '') {
            return $description;
        }

        return sprintf('Describe when a model should reach for %s.', Str::headline(class_basename($name)));
    }

    /**
     * Parse `--parameter` specs into an ordered list.
     *
     * Required parameters are emitted FIRST regardless of the order they were
     * given in. PHP will not accept a required argument after an optional one,
     * so honouring the caller's order here would generate a class that is a
     * fatal parse error — a generator that emits code which cannot load is
     * worse than one that reorders quietly, and the reorder is invisible to
     * the model, which addresses parameters by name.
     *
     * @return list<array{name: string, php: string, call: string, required: bool}>
     */
    protected function parameters(): array
    {
        /** @var list<string> $specs */
        $specs = (array) $this->option('parameter');

        $parsed = array_map($this->parse(...), $specs);

        $required = array_values(array_filter($parsed, fn (array $p): bool => $p['required']));
        $optional = array_values(array_filter($parsed, fn (array $p): bool => ! $p['required']));

        return [...$required, ...$optional];
    }

    /**
     * @return array{name: string, php: string, call: string, required: bool}
     */
    protected function parse(string $spec): array
    {
        [$name, $type, $description] = array_pad(explode(':', $spec, 3), 3, null);

        $name = Str::snake(trim((string) $name));

        if ($name === '') {
            throw new InvalidArgumentException(
                "The parameter [{$spec}] has no name. Expected name:type[:description]."
            );
        }

        $type = strtolower(trim((string) ($type ?? 'string')));
        $required = ! str_ends_with($type, '?');
        $type = rtrim($type, '?');

        $description = is_string($description) && trim($description) !== ''
            ? trim($description)
            : Str::headline($name).'.';

        return [
            'name' => $name,
            'required' => $required,
            'php' => $this->phpType($type, $spec),
            'call' => $this->builderCall($name, $type, $description, $required, $spec),
        ];
    }

    protected function phpType(string $type, string $spec): string
    {
        return match (true) {
            $type === '', $type === 'string' => 'string',
            $type === 'number', $type === 'float' => 'float',
            $type === 'integer', $type === 'int' => 'int',
            $type === 'boolean', $type === 'bool' => 'bool',
            str_starts_with($type, 'enum(') => 'string',
            default => throw $this->unsupported($type, $spec),
        };
    }

    protected function builderCall(string $name, string $type, string $description, bool $required, string $spec): string
    {
        $suffix = $required ? '' : ', required: false';

        if (str_starts_with($type, 'enum(')) {
            $cases = array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) Str::between($type, 'enum(', ')')),
            ), fn (string $case): bool => $case !== ''));

            if ($cases === []) {
                throw new InvalidArgumentException(
                    "The parameter [{$spec}] declares an enum with no cases. Expected enum(a,b,c)."
                );
            }

            return sprintf(
                "->withEnumParameter('%s', %s, [%s]%s)",
                $name,
                $this->quote($description),
                implode(', ', array_map($this->quote(...), $cases)),
                $suffix,
            );
        }

        $method = match ($type) {
            'number', 'float', 'integer', 'int' => 'withNumberParameter',
            'boolean', 'bool' => 'withBooleanParameter',
            default => 'withStringParameter',
        };

        return sprintf("->%s('%s', %s%s)", $method, $name, $this->quote($description), $suffix);
    }

    protected function unsupported(string $type, string $spec): InvalidArgumentException
    {
        // Array and object parameters are refused rather than half-generated.
        // Both need a Schema instance that cannot be expressed in a flat flag,
        // and emitting a broken `withArrayParameter(...)` for the developer to
        // repair is worse than saying so and naming the guide.
        if (in_array($type, ['array', 'object'], true)) {
            return new InvalidArgumentException(sprintf(
                'The parameter [%s] uses type [%s], which needs a Schema this flag cannot express. '
                .'Generate the tool without it and add ->withParameter(new %sSchema(...)) by hand — '
                .'see https://prism.echolabs.dev/core-concepts/schemas',
                $spec,
                $type,
                ucfirst($type),
            ));
        }

        return new InvalidArgumentException(sprintf(
            'The parameter [%s] uses unknown type [%s]. Supported: string, number, integer, boolean, enum(a,b,c).',
            $spec,
            $type,
        ));
    }

    /**
     * @param  list<array{name: string, php: string, call: string, required: bool}>  $parameters
     */
    protected function builderCalls(array $parameters): string
    {
        if ($parameters === []) {
            return '';
        }

        return "\n            ".implode("\n            ", array_column($parameters, 'call'));
    }

    /**
     * @param  list<array{name: string, php: string, call: string, required: bool}>  $parameters
     */
    protected function handlerSignature(array $parameters): string
    {
        return implode(', ', array_map(
            fn (array $p): string => $p['required']
                ? sprintf('%s $%s', $p['php'], $p['name'])
                : sprintf('?%s $%s = null', $p['php'], $p['name']),
            $parameters,
        ));
    }

    /**
     * Single-quoted PHP string literal.
     *
     * Descriptions are author-supplied prose and routinely contain apostrophes,
     * which would otherwise close the literal and generate a parse error.
     */
    protected function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
