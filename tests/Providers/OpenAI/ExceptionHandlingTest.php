<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenAI\OpenAI;

beforeEach(function (): void {
    $this->provider = new OpenAI(
        apiKey: 'test-key',
        url: 'https://api.openai.com/v1',
        organization: null,
        project: null
    );
});

function createOpenAIMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));
    $mockResponse->shouldReceive('header')->with('retry-after')->andReturn($headers['retry-after'] ?? '0');
    $mockResponse->shouldReceive('getHeaders')->andReturn([]);

    return $mockResponse;
}

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createOpenAIMockResponse(429, [
        'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
    ], ['retry-after' => '60']);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles provider overloaded errors (503)', function (): void {
    // OpenAI added "503 Service Unavailable" to its inference endpoints on
    // 2026-09-08. This arm listed only 529, so a 503 fell through to the
    // generic handler and surfaced as a PrismException — not the exception a
    // caller's retry logic keys on. Every other provider that can return 503
    // already mapped it; OpenAI was the only one that did not.
    $mockResponse = createOpenAIMockResponse(503, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles provider overloaded errors (529)', function (): void {
    $mockResponse = createOpenAIMockResponse(529, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles request too large errors (413)', function (): void {
    $mockResponse = createOpenAIMockResponse(413, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismRequestTooLargeException::class);
});

it('handles errors with detailed error information', function (): void {
    $mockResponse = createOpenAIMockResponse(400, [
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'Invalid model specified',
            'param' => 'model',
            'code' => 'model_not_found',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismException::class, 'OpenAI Error [400]: invalid_request_error - Invalid model specified');
});

it('handles errors without error type', function (): void {
    $mockResponse = createOpenAIMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismException::class, 'OpenAI Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createOpenAIMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismException::class, 'OpenAI Error [500]: Unknown error');
});

it('handles errors with array message', function (): void {
    $mockResponse = createOpenAIMockResponse(400, [
        'error' => [
            'type' => 'invalid_request_error',
            'message' => ['First error', 'Second error'],
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gpt-4o', $exception))
        ->toThrow(PrismException::class, 'OpenAI Error [400]: invalid_request_error - First error, Second error');
});
