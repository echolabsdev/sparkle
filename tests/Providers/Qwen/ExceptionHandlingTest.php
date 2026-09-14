<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Qwen\Qwen;

beforeEach(function (): void {
    $this->provider = new Qwen(
        apiKey: 'test-key',
        url: 'https://dashscope-intl.aliyuncs.com/api/v1'
    );
});

function createQwenMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    if (isset($headers['retry-after'])) {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(true);
        $mockResponse->shouldReceive('header')->with('retry-after')->andReturn($headers['retry-after']);
    } else {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(false);
    }

    return $mockResponse;
}

it('handles Arrearage errors', function (): void {
    $mockResponse = createQwenMockResponse(400, [
        'code' => 'Arrearage',
        'message' => 'Your account has insufficient balance',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Account Arrearage: Your account has insufficient balance');
});

it('handles DataInspectionFailed errors', function (): void {
    $mockResponse = createQwenMockResponse(400, [
        'code' => 'DataInspectionFailed',
        'message' => 'Input or output data may contain inappropriate content.',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Content Moderation Failed: Input or output data may contain inappropriate content.');
});

it('handles data_inspection_failed errors (snake_case variant)', function (): void {
    $mockResponse = createQwenMockResponse(400, [
        'code' => 'data_inspection_failed',
        'message' => 'Content moderation failed.',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Content Moderation Failed: Content moderation failed.');
});

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createQwenMockResponse(429, [
        'code' => '',
        'message' => 'Rate limit exceeded',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles overloaded errors (503)', function (): void {
    $mockResponse = createQwenMockResponse(503, [
        'code' => '',
        'message' => 'Service temporarily unavailable',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles errors with detailed error information', function (): void {
    $mockResponse = createQwenMockResponse(400, [
        'code' => 'invalid_request_error',
        'message' => 'Invalid request parameters',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Error [400]: invalid_request_error - Invalid request parameters');
});

it('handles errors without error type', function (): void {
    $mockResponse = createQwenMockResponse(500, [
        'code' => '',
        'message' => 'Internal server error',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createQwenMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('qwen-plus', $exception))
        ->toThrow(PrismException::class, 'Qwen Error [500]: Unknown error');
});
