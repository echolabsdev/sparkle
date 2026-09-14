<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Prism\Prism\Support\JsonMap;
use stdClass;

class ProviderTool
{
    /**
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $name = null,
        public readonly array $options = [],
    ) {}

    /**
     * The options as a JSON OBJECT — the shape a provider expects.
     *
     * A provider tool taking no options is the common case, and it is the one
     * PHP renders as `[]` rather than `{}`. Every send site used to spell the
     * guard for itself.
     */
    public function optionsAsObject(): stdClass
    {
        return JsonMap::of($this->options);
    }
}
