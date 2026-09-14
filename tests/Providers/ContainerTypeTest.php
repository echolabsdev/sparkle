<?php

declare(strict_types=1);

namespace Tests\Providers;

use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

/**
 * The guard for the defect this file is named after.
 *
 * `{}` and `[]` are the same PHP value and different JSON values, so a
 * map-typed field sent empty goes out as a list and the provider rejects it.
 * Every send site used to carry its own guard, which meant the rule held only
 * where somebody had remembered to write it — and three providers had not.
 *
 * These tests DISCOVER the send sites from the filesystem rather than listing
 * them, so a provider added tomorrow is covered without anyone editing this
 * file. That is the whole point: a list would have the same blind spot the
 * per-provider guards had.
 */
function toolMapClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../src/Providers/*/Maps/*ToolMap.php') ?: [] as $path) {
        $provider = basename(dirname($path, 2));
        $classes[$provider.'/'.basename($path, '.php')] = sprintf(
            'Prism\Prism\Providers\%s\Maps\%s',
            $provider,
            basename($path, '.php'),
        );
    }

    return $classes;
}

it('never sends a JSON list for a tool with no parameters', function (string $class): void {
    $tool = (new Tool)
        ->as('now')
        ->for('Returns the current time')
        ->using(fn (): string => 'now');

    $payload = json_encode($class::map([$tool]));

    expect($payload)->not->toContain('"properties":[]')
        ->and($payload)->not->toContain('"parameters":[]');
})->with(toolMapClasses());

it('never sends a JSON list for a tool whose parameters are present', function (string $class): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searches')
        ->withStringParameter('query', 'the query')
        ->using(fn (): string => '[]');

    expect(json_encode($class::map([$tool])))->toContain('"properties":{"query"');
})->with(toolMapClasses());

it('leaves no provider free to decide the container type for tool call arguments', function (): void {
    // ToolCall::argumentsAsObject() / argumentsAsJson() / hasArguments() are
    // the only sanctioned way in. A map reaching for arguments() directly is
    // back to deciding the JSON type for itself, which is the bug.
    $offenders = [];

    foreach (glob(__DIR__.'/../../src/Providers/*/Maps/*.php') ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), '->arguments()')) {
            $offenders[] = basename(dirname($path, 2)).'/'.basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('sends a property declared as an empty schema as an object, not a list', function (string $class): void {
    // An MCP server declaring `"anything": {}` means "any value". Decoded into
    // PHP it is indistinguishable from a list, and it went out as one.
    $tool = (new Tool)
        ->as('echo')
        ->for('Echoes anything')
        ->withParameter(new RawSchema('anything', []))
        ->using(fn (): string => 'ok');

    expect(json_encode($class::map([$tool])))->toContain('"anything":{}');
})->with(toolMapClasses());
