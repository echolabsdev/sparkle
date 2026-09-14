<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\ValueObjects;

readonly class ReplicatePrediction
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $urls
     * @param  array<string, float>  $metrics
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $input,
        public mixed $output,
        public ?string $error,
        public ?string $logs,
        public array $urls,
        public array $metrics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            status: $data['status'],
            input: $data['input'] ?? [],
            output: $data['output'] ?? null,
            error: $data['error'] ?? null,
            logs: $data['logs'] ?? null,
            urls: $data['urls'] ?? [],
            metrics: $data['metrics'] ?? [],
        );
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'canceled']);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'canceled']);
    }
}
