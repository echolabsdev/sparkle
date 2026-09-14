<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Support\JsonMap;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class ProviderToolCall implements Arrayable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public array $data,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'data' => JsonMap::of($this->data),
        ];
    }
}
