<?php

namespace EchoLabs\Prism\Drivers\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Client
{
    private readonly PendingRequest $client;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey
    ) {
        $this->client = Http::baseUrl($this->baseUrl)->withHeaders([
            'Content-Type' => 'application/json',
        ]);
    }

    public function messages(
        string $model,
        array $contents,
        ?array $systemInstruction = null,
        ?array $tools = null,
        ?array $generationConfig = null,
        ?array $safetySettings = null
    ): Response {
        $endpoint = "models/{$model}:generateContent";

        $payload = [
            'contents' => array_map(fn ($content): array => [
                'role' => $content['role'] ?? 'user',
                'parts' => array_map(fn ($part) => is_string($part) ? ['text' => $part] : $part, $content['parts'] ?? [$content]),
            ], $contents),
        ];

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        if ($tools !== null) {
            $payload['tools'] = $tools;
        }

        if ($generationConfig !== null) {
            $payload['generationConfig'] = $generationConfig;
        }

        if ($safetySettings !== null) {
            $payload['safetySettings'] = $safetySettings;
        }

        return $this->client->post($endpoint.'?key='.$this->apiKey, $payload);
    }
}
