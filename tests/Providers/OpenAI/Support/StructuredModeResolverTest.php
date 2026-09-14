<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Support;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;

it('resolves structured mode for exact-match models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-4o',
    'gpt-4o-mini',
    'gpt-4o-mini-2024-07-18',
    'gpt-4o-2024-08-06',
    'chatgpt-4o-latest',
    'o3-mini',
    'o3-mini-2025-01-31',
]);

it('resolves structured mode for gpt-4.1+ family models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-4.1',
    'gpt-4.1-nano',
    'gpt-4.1-mini',
    'gpt-4.1-nano-2025-04-14',
    'gpt-4.1-mini-2025-04-14',
    'gpt-4.5-preview',
    'gpt-4.5-preview-2025-02-27',
]);

it('resolves structured mode for gpt-5 family models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-5',
    'gpt-5-mini',
    'gpt-5-nano',
    'gpt-5-mini-2025-08-07',
    'gpt-5-nano-2025-08-07',
    'gpt-5.1',
    'gpt-5.1-2025-10-01',
    'gpt-5.2',
    'gpt-5.2-2025-12-11',
    'gpt-5.4',
]);

it('resolves json mode for models without structured support', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Json);
})->with([
    'gpt-4-turbo',
    'gpt-4-0125-preview',
    'gpt-3.5-turbo',
    'some-custom-model',
]);

it('throws for unsupported models', function (string $model): void {
    StructuredModeResolver::forModel($model);
})->with([
    'o1-mini',
    'o1-mini-2024-09-12',
    'o1-preview',
    'o1-preview-2024-09-12',
])->throws(PrismException::class);
