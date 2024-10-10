<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Drivers\OpenAI;

use EchoLabs\Prism\Contracts\Driver;
use EchoLabs\Prism\Drivers\DriverResponse;
use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Requests\TextRequest;
use EchoLabs\Prism\ValueObjects\ToolCall;
use EchoLabs\Prism\ValueObjects\Usage;
use Throwable;

class OpenAI implements Driver
{
    protected Client $client;

    protected string $model;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $url,
        public readonly ?string $organization,
    ) {
        $this->client = new Client(
            apiKey: $this->apiKey,
            url: $this->url,
            organization: $this->organization,
        );
    }

    #[\Override]
    public function usingModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    #[\Override]
    public function text(TextRequest $request): DriverResponse
    {
        try {
            $response = $this->client->messages(
                model: $this->model,
                messages: (new OpenAIMessageMap(
                    $request->messages,
                    $request->systemPrompt ?? '',
                ))(),
                maxTokens: $request->maxTokens,
                temperature: $request->temperature,
                topP: $request->topP,
                tools: OpenAITool::map($request->tools),
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($this->model, $e);
        }

        $data = $response->json();

        if (data_get($data, 'error') || ! $data) {
            throw PrismException::providerResponseError(vsprintf(
                'OpenAI Error:  [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }

        $toolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), data_get($data, 'choices.0.message.tool_calls', []));

        return new DriverResponse(
            text: data_get($data, 'choices.0.message.content') ?? '',
            toolCalls: $toolCalls,
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            finishReason: $this->mapFinishReason(data_get($data, 'choices.0.finish_reason', '')),
            response: [
                'id' => data_get($data, 'id'),
                'model' => data_get($data, 'model'),
            ]
        );
    }

    protected function mapFinishReason(string $stopReason): FinishReason
    {
        return match ($stopReason) {
            'stop', => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
