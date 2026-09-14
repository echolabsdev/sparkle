<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Embeddings for Replicate', function (): void {
    it('returns embeddings from input', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/embeddings-single-input');

        $response = Prism::embeddings()
            ->using('replicate', 'mark3labs/embeddings-gte-base')
            ->fromInput('The food was delicious and the waiter...')
            ->asEmbeddings();

        expect($response->embeddings)->toBeArray()
            ->and($response->embeddings)->toHaveCount(1)
            ->and($response->embeddings[0])->toBeInstanceOf(Embedding::class)
            ->and($response->embeddings[0]->embedding)->toBeArray()
            ->and($response->embeddings[0]->embedding)->not->toBeEmpty()
            ->and($response->usage->tokens)->toBeGreaterThan(0);
    });

    it('works with multiple embeddings', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/embeddings-multiple-inputs');

        $response = Prism::embeddings()
            ->using('replicate', 'mark3labs/embeddings-gte-base')
            ->fromArray([
                'The food was delicious.',
                'The drinks were not so good',
            ])
            ->asEmbeddings();

        expect($response->embeddings)->toBeArray()
            ->and($response->embeddings)->toHaveCount(2)
            ->and($response->embeddings[0]->embedding)->toBeArray()
            ->and($response->embeddings[1]->embedding)->toBeArray()
            ->and($response->usage->tokens)->toBeGreaterThan(0);
    });

    it('includes model information in meta', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/embeddings-single-input');

        $response = Prism::embeddings()
            ->using('replicate', 'mark3labs/embeddings-gte-base')
            ->fromInput('Test input')
            ->asEmbeddings();

        expect($response->meta->model)->toBe('mark3labs/embeddings-gte-base');
    });
});
