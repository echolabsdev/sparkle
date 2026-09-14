<?php

declare(strict_types=1);

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Providers\Qwen\Maps\ToolChoiceMap;

it('maps auto tool choice', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Auto))->toBe('auto');
});

it('maps null tool choice', function (): void {
    expect(ToolChoiceMap::map(null))->toBeNull();
});

it('throws exception for string tool choice (forcing specific tool)', function (): void {
    expect(fn (): ?string => ToolChoiceMap::map('weather'))
        ->toThrow(
            InvalidArgumentException::class,
            'Qwen does not support forcing a specific tool. Only "auto" and "none" are supported.'
        );
});
