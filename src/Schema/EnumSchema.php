<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Contracts\Schema;

readonly class EnumSchema implements Schema
{
    /**
     * @param  array<int, string|int|float>  $options
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $options,
        public bool $nullable = false,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'enum' => $this->options(),
            'type' => $this->types(),
        ];
    }

    /**
     * @return array<int, string|int|float|null>
     */
    protected function options(): array
    {
        $options = $this->options;

        if ($this->nullable) {
            $options[] = null;
        }

        return $options;
    }

    /**
     * @return string[]|string
     */
    protected function types(): array|string
    {
        $types = $this->resolveTypes();

        if ($this->nullable) {
            $types[] = 'null';
        }

        if ($this->hasSingleType($types)) {
            return $types[0];
        }

        return $types;
    }

    /**
     * @param  string[]  $types
     */
    protected function hasSingleType(array $types): bool
    {
        return count($types) === 1;
    }

    /**
     * @return string[]
     */
    protected function resolveTypes(): array
    {
        return collect($this->options)
            ->map(fn (mixed $option): string => match (gettype($option)) {
                'integer', 'double' => 'number',
                'string' => 'string'
            })
            ->unique()
            ->values()
            ->toArray();

    }
}
