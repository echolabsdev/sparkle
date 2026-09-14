<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('api/embed', 'ollama/embeddings-input');

    $response = Prism::embeddings()
        ->using(Provider::Ollama, 'mxbai-embed-large')
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): true {
        expect($request->data()['model'])->toBe('mxbai-embed-large');
        expect($request->data()['input'])->toBe(['The food was delicious and the waiter...']);
        expect($request->data())->not->toHaveKeys(['options']);

        return true;
    });

    $embeddings = json_decode(file_get_contents('tests/Fixtures/ollama/embeddings-input-1.json'), true);
    $embeddings = array_map(Embedding::fromArray(...), data_get($embeddings, 'embeddings'));

    expect($response->meta->model)->toBe('mxbai-embed-large');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(10);
});

it('returns embeddings from file', function (): void {
    FixtureResponse::fakeResponseSequence('api/embed', 'ollama/embeddings-file');

    $response = Prism::embeddings()
        ->using(Provider::Ollama, 'mxbai-embed-large')
        ->fromFile('tests/Fixtures/test-embedding-file.md')
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/ollama/embeddings-file-1.json'), true);
    $embeddings = array_map(Embedding::fromArray(...), data_get($embeddings, 'embeddings'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(512);
});

it('works with multiple embeddings', function (): void {
    FixtureResponse::fakeResponseSequence('api/embed', 'ollama/embeddings-multiple-inputs');

    $response = Prism::embeddings()
        ->using(Provider::Ollama, 'mxbai-embed-large')
        ->fromArray([
            'The food was delicious.',
            'The drinks were not so good',
        ])
        ->asEmbeddings();

    $embeddings = json_decode(file_get_contents('tests/Fixtures/ollama/embeddings-multiple-inputs-1.json'), true);
    $embeddings = array_map(Embedding::fromArray(...), data_get($embeddings, 'embeddings'));

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->embeddings[1]->embedding)->toBe($embeddings[1]->embedding);
    expect($response->usage->tokens)->toBe(522);
});

it('allows setting provider options like dimensions', function (): void {
    FixtureResponse::fakeResponseSequence('api/embed', 'ollama/embeddings-with-dimensions');

    $response = Prism::embeddings()
        ->using(Provider::Ollama, 'mxbai-embed-large')
        ->withProviderOptions([
            'dimensions' => 256,
        ])
        ->fromInput('The food was delicious and the waiter...')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): true {
        expect($request->data()['dimensions'])->toBe(256);
        expect($request->data())->not->toHaveKeys(['options']);

        return true;
    });

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/ollama/embeddings-with-dimensions-1.json'),
        true
    );
    $embeddings = array_map(Embedding::fromArray(...), data_get($embeddings, 'embeddings'));

    expect($response->meta->model)->toBe('mxbai-embed-large');
    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
});
