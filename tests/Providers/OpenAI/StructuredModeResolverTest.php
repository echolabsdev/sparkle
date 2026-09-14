<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;

it('resolves structured mode for supported models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-4o-mini',
    'gpt-4o-mini-2024-07-18',
    'gpt-4o-2024-08-06',
    'gpt-4o',
    'chatgpt-4o-latest',
    'o3-mini',
    'o3-mini-2025-01-31',
    'gpt-4.1',
    'gpt-4.1-nano',
    'gpt-4.1-mini',
    'gpt-4.5-preview',
    'gpt-4.5-preview-2025-02-27',
    'gpt-5',
    'gpt-5-mini',
    'gpt-5-nano',
    'gpt-5.1',
    'gpt-5.2',
    'gpt-5.4',
]);

it('resolves json mode for unsupported structured models', function (): void {
    expect(StructuredModeResolver::forModel('gpt-3.5-turbo'))->toBe(StructuredMode::Json);
});

it('throws for unsupported models', function (): void {
    StructuredModeResolver::forModel('o1-mini');
})->throws(PrismException::class, 'Structured output is not supported for o1-mini');

it('resolves structured mode for fine-tuned models based on supported base models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'ft:gpt-4o:my-org:custom-name:abc123',
    'ft:gpt-4o-mini:my-org:custom-name:abc123',
    'ft:gpt-4o-mini-2024-07-18:my-org:custom-name:abc123',
    'ft:gpt-4.1-mini:company:model-name:hash',
    'ft:gpt-4.1:company:model-name:hash',
    'ft:gpt-4.1-mini-2025-04-14:company:model-name:hash',
    'ft:gpt-4o-2024-08-06:my-org:custom-name:abc123',
]);

it('resolves json mode for fine-tuned models based on unsupported structured base models', function (): void {
    expect(StructuredModeResolver::forModel('ft:gpt-3.5-turbo:my-org:custom-name:abc123'))->toBe(StructuredMode::Json);
});

it('throws for fine-tuned models based on unsupported base models', function (): void {
    StructuredModeResolver::forModel('ft:o1-mini:my-org:custom-name:abc123');
})->throws(PrismException::class, 'Structured output is not supported for ft:o1-mini:my-org:custom-name:abc123');
