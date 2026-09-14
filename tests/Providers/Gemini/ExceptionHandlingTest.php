<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Gemini\Gemini;

beforeEach(function (): void {
    $this->provider = new Gemini(
        apiKey: 'test-key',
        url: 'https://generativelanguage.googleapis.com/v1beta'
    );
});

function createGeminiMockResponse(int $statusCode, array $json = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    return $mockResponse;
}

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createGeminiMockResponse(429, [
        'error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Rate limit exceeded'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gemini-pro', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('extracts retry_after and rate limit details from quota violations', function (): void {
    $mockResponse = createGeminiMockResponse(429, [
        'error' => [
            'code' => 429,
            'message' => 'You exceeded your current quota. Please retry in 35.458759309s.',
            'status' => 'RESOURCE_EXHAUSTED',
            'details' => [
                [
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [
                        [
                            'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_paid_tier_input_token_count',
                            'quotaId' => 'GenerateContentPaidTierInputTokensPerModelPerMinute',
                            'quotaValue' => '16000',
                        ],
                    ],
                ],
                [
                    '@type' => 'type.googleapis.com/google.rpc.RetryInfo',
                    'retryDelay' => '35s',
                ],
            ],
        ],
    ]);
    $exception = new RequestException($mockResponse);

    try {
        $this->provider->handleRequestException('gemini-pro', $exception);
        $this->fail('Expected PrismRateLimitedException to be thrown.');
    } catch (PrismRateLimitedException $e) {
        expect($e->retryAfter)->toBe(35)
            ->and($e->rateLimits)->toHaveCount(1)
            ->and($e->rateLimits[0]->name)->toBe('GenerateContentPaidTierInputTokensPerModelPerMinute')
            ->and($e->rateLimits[0]->limit)->toBe(16000)
            ->and($e->rateLimits[0]->remaining)->toBeNull()
            ->and($e->rateLimits[0]->resetsAt)->not->toBeNull();
    }
});

it('handles provider overloaded errors (503)', function (): void {
    $mockResponse = createGeminiMockResponse(503, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gemini-pro', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles errors with status and message', function (): void {
    $mockResponse = createGeminiMockResponse(400, [
        'error' => [
            'status' => 'INVALID_ARGUMENT',
            'message' => 'Invalid value at \'contents\' (type.googleapis.com/google.ai.generativelanguage.v1beta.Content)',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gemini-pro', $exception))
        ->toThrow(PrismException::class, 'Gemini Error [400]: INVALID_ARGUMENT - Invalid value at \'contents\'');
});

it('handles errors without error status', function (): void {
    $mockResponse = createGeminiMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gemini-pro', $exception))
        ->toThrow(PrismException::class, 'Gemini Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createGeminiMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('gemini-pro', $exception))
        ->toThrow(PrismException::class, 'Gemini Error [500]: Unknown error');
});
