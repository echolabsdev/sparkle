<?php

declare(strict_types=1);

namespace Prism\Prism\Fim;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Response implements Arrayable
{
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
        ];
    }
}
