<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Drivers\Google;

use EchoLabs\Prism\Contracts\Driver;
use EchoLabs\Prism\Drivers\DriverResponse;
use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Requests\TextRequest;
use EchoLabs\Prism\ValueObjects\ToolCall;
use EchoLabs\Prism\ValueObjects\Usage;
use Throwable;

class Google implements Driver
{
    protected Client $client;

    protected string $model; // A reasonable default, adjust as needed

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey
    ) {
        $this->client = new Client($this->baseUrl, $this->apiKey);
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
        $messageMap = new GoogleMessageMap($request->messages);
        $mappedMessages = $messageMap();
        $tools = GoogleTool::map($request->tools);
        $toolMap = collect($request->tools)->keyBy->name();

        $finalText = '';
        $allToolCalls = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $step = 0;

        do {
            try {
                $response = $this->client->messages(
                    model: $this->model,
                    contents: $mappedMessages['contents'],
                    systemInstruction: $mappedMessages['system_instruction'] ?? null,
                    tools: $tools,
                    generationConfig: [
                        'temperature' => $request->temperature,
                        'topP' => $request->topP,
                        'topK' => $request->topK ?? null,
                        'maxOutputTokens' => $request->maxTokens,
                    ]
                );
            } catch (Throwable $e) {
                throw PrismException::providerRequestError($this->model, $e);
            }

            $data = $response->json();

            if (isset($data['error'])) {
                throw PrismException::providerResponseError(
                    "Google Error: [{$data['error']['code']}] {$data['error']['message']}"
                );
            }

            $candidates = $data['candidates'][0];
            $content = $candidates['content'];

            $stepText = '';
            $stepToolCalls = [];

            foreach ($content['parts'] as $part) {
                if (isset($part['text'])) {
                    $stepText .= $part['text'];
                }

                if (isset($part['functionCall'])) {
                    $toolCall = new ToolCall(
                        id: $part['functionCall']['name'].'_'.$step,
                        name: $part['functionCall']['name'],
                        arguments: $part['functionCall']['args']
                    );
                    $stepToolCalls[] = $toolCall;

                    if ($tool = $toolMap->get($toolCall->name)) {
                        $result = $tool->handle(...$toolCall->arguments());

                        // Add tool result to the conversation
                        $mappedMessages['contents'][] = [
                            'role' => 'user',
                            'parts' => [['text' => "Tool result for {$toolCall->name}: {$result}"]],
                        ];
                    }
                }
            }

            $finalText .= $stepText;
            $allToolCalls = [...$allToolCalls, ...$stepToolCalls];

            $totalPromptTokens += $data['usageMetadata']['promptTokenCount'];
            $totalCompletionTokens += $data['usageMetadata']['candidatesTokenCount'];

            $lastFinishReason = $this->mapFinishReason($candidates['finishReason']);

            // Stop if we've reached the maximum number of tokens
            if ($totalPromptTokens + $totalCompletionTokens >= $request->maxTokens) {
                $lastFinishReason = FinishReason::Length;
                break;
            }

            $step++;
        } while ($stepToolCalls !== []);

        return new DriverResponse(
            text: $finalText,
            toolCalls: $allToolCalls,
            usage: new Usage($totalPromptTokens, $totalCompletionTokens),
            finishReason: $lastFinishReason,
            response: [
                'model' => $this->model,
                'id' => (string) time(),
            ]
        );
    }

    protected function mapFinishReason(string $finishReason): FinishReason
    {
        return match ($finishReason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY' => FinishReason::ContentFilter,
            'RECITATION' => FinishReason::ContentFilter,
            'OTHER' => FinishReason::Unknown,
            default => FinishReason::Unknown,
        };
    }
}
