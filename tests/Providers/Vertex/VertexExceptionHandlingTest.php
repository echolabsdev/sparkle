<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.vertex.project_id', 'test-project');
    config()->set('prism.providers.vertex.region', 'us-central1');
    config()->set('prism.providers.vertex.access_token', 'test-access-token');
});

describe('Exception handling for Vertex', function (): void {
    it('throws PrismRateLimitedException on 429 response', function (): void {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asText();
    })->throws(PrismRateLimitedException::class);

    it('throws PrismException on error response', function (): void {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid request',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asText();
    })->throws(PrismException::class);

    it('throws PrismException on authentication error', function (): void {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'Request had invalid authentication credentials',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401),
        ]);

        Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asText();
    })->throws(PrismException::class);

    it('throws PrismException on permission denied', function (): void {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Permission denied on resource',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        Prism::text()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withPrompt('Hello')
            ->asText();
    })->throws(PrismException::class);
});
