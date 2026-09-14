<?php

declare(strict_types=1);

use App\Tools\AdderTool;
use Illuminate\Support\Facades\File;

/**
 * `make:prism-tool`.
 *
 * The generator's output is PHP that somebody else has to load, so most of
 * these assert the generated SOURCE and the last one asserts the generated
 * class actually runs. A generator whose output is a parse error still passes
 * every string assertion you can write about it.
 */
function generatedTool(string $class): string
{
    return File::get(app_path(str_replace('\\', '/', $class).'.php'));
}

afterEach(function (): void {
    File::deleteDirectory(app_path('Tools'));
});

it('names the tool for the model, not for PHP', function (): void {
    // `SearchTool` is a PHP naming convention. The model sees `search`:
    // providers expect snake_case, and a trailing "Tool" means nothing to a
    // model while costing tokens in every request that lists it.
    $this->artisan('make:prism-tool', ['name' => 'SearchTool'])->assertSuccessful();

    expect(generatedTool('Tools/SearchTool'))
        ->toContain("->as('search')")
        ->toContain('namespace App\Tools;')
        ->toContain('class SearchTool extends Tool');
});

it('keeps a name that is not suffixed with Tool', function (): void {
    $this->artisan('make:prism-tool', ['name' => 'WeatherLookup'])->assertSuccessful();

    expect(generatedTool('Tools/WeatherLookup'))->toContain("->as('weather_lookup')");
});

it('builds parameters and a matching handler signature', function (): void {
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--parameter' => ['query:string:Detailed search query', 'limit:integer:How many results'],
    ])->assertSuccessful();

    expect(generatedTool('Tools/SearchTool'))
        ->toContain("->withStringParameter('query', 'Detailed search query')")
        ->toContain("->withNumberParameter('limit', 'How many results')")
        ->toContain('public function __invoke(string $query, int $limit): string');
});

it('puts optional parameters last, whatever order they were given in', function (): void {
    // PHP will not accept a required argument after an optional one. Honouring
    // the caller's order here would emit a class that is a fatal parse error,
    // and the reorder is invisible to the model, which addresses parameters by
    // name rather than by position.
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--parameter' => ['limit:integer?:How many', 'query:string:What to look for'],
    ])->assertSuccessful();

    expect(generatedTool('Tools/SearchTool'))
        ->toContain('public function __invoke(string $query, ?int $limit = null): string')
        ->toContain("->withNumberParameter('limit', 'How many', required: false)");
});

it('generates an enum parameter with its cases', function (): void {
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--parameter' => ['scope:enum(web,news,images):Where to search'],
    ])->assertSuccessful();

    expect(generatedTool('Tools/SearchTool'))
        ->toContain("->withEnumParameter('scope', 'Where to search', ['web', 'news', 'images'])")
        ->toContain('public function __invoke(string $scope): string');
});

it('survives an apostrophe in a description', function (): void {
    // Descriptions are author prose and routinely contain apostrophes. An
    // unescaped one closes the string literal and the generated file will not
    // parse — which no assertion about substrings would have caught.
    $this->artisan('make:prism-tool', [
        'name' => 'ProfileTool',
        '--description' => "Look up a user's profile",
    ])->assertSuccessful();

    $source = generatedTool('Tools/ProfileTool');

    expect($source)->toContain("Look up a user\\'s profile");

    $file = tempnam(sys_get_temp_dir(), 'prism').'.php';
    file_put_contents($file, $source);
    expect(shell_exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file))))
        ->toContain('No syntax errors');
    @unlink($file);
});

it('refuses an array parameter instead of generating something broken', function (): void {
    // An array or object parameter needs a Schema instance a flat flag cannot
    // express. Emitting a half-written withArrayParameter() for the developer
    // to repair is worse than saying so and naming the guide.
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--parameter' => ['tags:array:Some tags'],
    ])->assertFailed();
});

it('refuses an unknown type by name', function (): void {
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--parameter' => ['when:datetime:A date'],
    ])->assertFailed();
});

it('generates a tool Prism can actually call', function (): void {
    // The assertion that matters. Everything above checks the source LOOKS
    // right; this loads it and drives it through Prism's own handler
    // resolution, which is what proves the shape is the shape Prism resolves
    // — no ->using() line, __invoke as the handler, coerced arguments.
    $this->artisan('make:prism-tool', [
        'name' => 'AdderTool',
        '--parameter' => ['left:integer:Left operand', 'right:integer:Right operand'],
    ])->assertSuccessful();

    $path = app_path('Tools/AdderTool.php');

    File::put($path, str_replace(
        ["        //\n\n        return 'The result the model will read.';", 'namespace App\Tools;'],
        ['        return (string) ($left + $right);', 'namespace App\Tools;'],
        File::get($path),
    ));

    require_once $path;

    $tool = new AdderTool;

    expect($tool->name())->toBe('adder')
        ->and($tool->hasParameters())->toBeTrue()
        // Models routinely serialize every argument as a string. Prism coerces
        // against the handler signature the generator wrote, so this is also a
        // check that the signature and the schema agree.
        ->and($tool->handle(left: '2', right: '3'))->toBe('5');
});

it('produces exactly what the documentation says it produces', function (): void {
    // The docs show this command and the block it generates. Prose is the one
    // thing nothing here tests, so the example is pinned: if the generator's
    // output moves, this fails rather than leaving the guide quietly wrong.
    // See docs/core-concepts/tools-function-calling.md, "Generating a Tool Class".
    $this->artisan('make:prism-tool', [
        'name' => 'SearchTool',
        '--description' => 'Search the web for current events',
        '--parameter' => [
            'query:string:What to search for',
            'scope:enum(web,news,images):Which index to search',
            'limit:integer?:How many results to return',
        ],
    ])->assertSuccessful();

    expect(generatedTool('Tools/SearchTool'))
        ->toContain("->as('search')")
        ->toContain("->for('Search the web for current events')")
        ->toContain("->withStringParameter('query', 'What to search for')")
        ->toContain("->withEnumParameter('scope', 'Which index to search', ['web', 'news', 'images'])")
        ->toContain("->withNumberParameter('limit', 'How many results to return', required: false)")
        ->toContain('public function __invoke(string $query, string $scope, ?int $limit = null): string');
});
