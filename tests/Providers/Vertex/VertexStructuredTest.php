<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertex.project_id', 'test-project');
    config()->set('prism.providers.vertex.region', 'us-central1');
    config()->set('prism.providers.vertex.access_token', 'test-access-token');
});

describe('Structured output for Vertex', function (): void {
    it('can generate structured output', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/structured-response');

        $schema = new ObjectSchema(
            name: 'user',
            description: 'A user object',
            properties: [
                new StringSchema('name', 'The user\'s name'),
                new NumberSchema('age', 'The user\'s age'),
                new StringSchema('email', 'The user\'s email'),
            ],
            requiredFields: ['name', 'age', 'email']
        );

        $response = Prism::structured()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withSchema($schema)
            ->withPrompt('Generate a user object for John Doe, age 30, with email john.doe@example.com')
            ->generate();

        expect($response->structured)->toBe([
            'name' => 'John Doe',
            'age' => 30,
            'email' => 'john.doe@example.com',
        ])
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->usage->promptTokens)->toBe(50)
            ->and($response->usage->completionTokens)->toBe(25);
    });

    it('sends requests with response schema to Vertex AI', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/structured-response');

        $schema = new ObjectSchema(
            name: 'user',
            description: 'A user object',
            properties: [
                new StringSchema('name', 'The user\'s name'),
                new NumberSchema('age', 'The user\'s age'),
            ],
            requiredFields: ['name', 'age']
        );

        Prism::structured()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withSchema($schema)
            ->withPrompt('Generate a user')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            expect($data['generationConfig'])->toHaveKey('response_mime_type', 'application/json')
                ->and($data['generationConfig'])->toHaveKey('response_schema');

            return true;
        });
    });

    it('sends requests to the correct Vertex AI endpoint for structured output', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'vertex/structured-response');

        $schema = new ObjectSchema(
            name: 'test',
            description: 'Test',
            properties: [
                new StringSchema('value', 'Test value'),
            ],
            requiredFields: ['value']
        );

        Prism::structured()
            ->using(Provider::Vertex, 'gemini-1.5-flash')
            ->withSchema($schema)
            ->withPrompt('Test')
            ->generate();

        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toContain('us-central1-aiplatform.googleapis.com')
                ->and($request->url())->toContain('projects/test-project')
                ->and($request->url())->toContain('gemini-1.5-flash:generateContent');

            return true;
        });
    });
});
