<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.qwen.api_key', env('QWEN_API_KEY'));
});

it('returns embeddings from input', function (): void {
    FixtureResponse::fakeResponseSequence('text-embedding/text-embedding', 'qwen/embeddings');

    $response = Prism::embeddings()
        ->using(Provider::Qwen, 'text-embedding-v4')
        ->fromInput('Hello, how are you?')
        ->asEmbeddings();

    $embeddings = json_decode(
        file_get_contents('tests/Fixtures/qwen/embeddings-1.json'),
        true
    );

    $embeddings = array_map(
        fn (array $item): Embedding => Embedding::fromArray($item['embedding']),
        data_get($embeddings, 'output.embeddings')
    );

    expect($response->meta->model)->toBe('text-embedding-v4');
    expect($response->meta->id)->toBe('118f9c73-7878-927a-92da-4a276b224421');

    expect($response->embeddings)->toBeArray();
    expect($response->embeddings[0]->embedding)->toBe($embeddings[0]->embedding);
    expect($response->usage->tokens)->toBe(7);
});

it('sends correct request to embeddings endpoint', function (): void {
    FixtureResponse::fakeResponseSequence('text-embedding/text-embedding', 'qwen/embeddings');

    Prism::embeddings()
        ->using(Provider::Qwen, 'text-embedding-v4')
        ->fromInput('Hello, how are you?')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-v4'
            && $body['input'] === ['texts' => ['Hello, how are you?']];
    });
});

it('supports dimensions provider option', function (): void {
    FixtureResponse::fakeResponseSequence('text-embedding/text-embedding', 'qwen/embeddings');

    Prism::embeddings()
        ->using(Provider::Qwen, 'text-embedding-v4')
        ->withProviderOptions([
            'dimensions' => 512,
        ])
        ->fromInput('Hello, how are you?')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-v4'
            && $body['parameters']['dimensions'] === 512;
    });
});
