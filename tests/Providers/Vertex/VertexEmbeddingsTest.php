<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertex.project_id', 'test-project');
    config()->set('prism.providers.vertex.region', 'us-central1');
    config()->set('prism.providers.vertex.access_token', 'test-access-token');
});

describe('Embeddings for Vertex', function (): void {
    it('can generate embeddings', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/embeddings');

        $response = Prism::embeddings()
            ->using(Provider::Vertex, 'text-embedding-004')
            ->fromInput('Hello, world!')
            ->generate();

        expect($response->embeddings)->toHaveCount(1)
            ->and($response->embeddings[0]->embedding)->toBeArray()
            ->and($response->embeddings[0]->embedding)->toHaveCount(10)
            ->and($response->usage->tokens)->toBe(20)
            ->and($response->meta->model)->toBe('text-embedding-004');
    });

    it('sends requests to the correct Vertex AI endpoint for embeddings', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/embeddings');

        Prism::embeddings()
            ->using(Provider::Vertex, 'text-embedding-004')
            ->fromInput('Hello, world!')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toContain('us-central1-aiplatform.googleapis.com')
                ->and($request->url())->toContain('projects/test-project')
                ->and($request->url())->toContain('locations/us-central1')
                ->and($request->url())->toContain('publishers/google/models')
                ->and($request->url())->toContain('text-embedding-004:predict')
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->header('Authorization')[0])->toBe('Bearer test-access-token');

            return true;
        });
    });

    it('includes content in request body', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/embeddings');

        Prism::embeddings()
            ->using(Provider::Vertex, 'text-embedding-004')
            ->fromInput('Hello, world!')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            expect($data['instances'])->toHaveCount(1)
                ->and($data['instances'][0]['content'])->toBe('Hello, world!');

            return true;
        });
    });

    it('throws exception for multiple inputs', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/embeddings');

        Prism::embeddings()
            ->using(Provider::Vertex, 'text-embedding-004')
            ->fromInput('Hello')
            ->fromInput('World')
            ->generate();
    })->throws(PrismException::class, 'Vertex Error: Prism currently only supports one input at a time with Vertex AI.');
});
