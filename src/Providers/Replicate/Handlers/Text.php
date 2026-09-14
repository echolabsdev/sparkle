<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\Providers\Replicate\Maps\FinishReasonMap;
use Prism\Prism\Providers\Replicate\Maps\MessageMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use HandlesPredictions;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        // Build payload
        $payload = $this->buildPayload($request);

        // Create prediction and wait for completion (uses sync mode if enabled)
        $prediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Check for errors
        if ($prediction->isFailed()) {
            throw new PrismException(
                "Replicate prediction failed: {$prediction->error}"
            );
        }

        // Extract the text output
        $text = $this->extractTextFromOutput($prediction->output);

        // Create assistant message
        $responseMessage = new AssistantMessage(
            content: $text,
        );

        $request->addMessage($responseMessage);

        // Add step to response builder
        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: FinishReasonMap::map($prediction->status),
            toolCalls: [],
            toolResults: [],
            providerToolCalls: [],
            usage: new Usage(
                promptTokens: 0, // Replicate doesn't provide token counts
                completionTokens: 0,
            ),
            meta: new Meta(
                id: $prediction->id,
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [
                'metrics' => $prediction->metrics,
            ],
        ));

        return $this->responseBuilder->toResponse();
    }

    /**
     * Build the prediction payload.
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(Request $request): array
    {
        return [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => $this->buildInput($request),
        ];
    }

    /**
     * Build input parameters.
     *
     * @return array<string, mixed>
     */
    protected function buildInput(Request $request): array
    {
        $input = ['prompt' => MessageMap::map($request->messages())];

        // Build system prompt
        if ($request->systemPrompts() !== []) {
            $input['system_prompt'] = implode("\n\n", array_map(
                fn ($prompt): string => $prompt->content,
                $request->systemPrompts()
            ));
        }

        // Add other parameters
        $params = $this->buildInputParameters($request);

        return array_merge($input, $params);
    }

    /**
     * Extract version from model string for the Replicate API.
     * The version field accepts:
     * - "owner/model:version" format (uses specific version)
     * - "owner/model" format (uses latest version)
     * - Just "version_hash" (64-char hash)
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Replicate API accepts the full string as-is
        // It handles owner/model, owner/model:version, or version hash formats
        return $model;
    }

    /**
     * Build input parameters from request.
     *
     * @return array<string, mixed>
     */
    protected function buildInputParameters(Request $request): array
    {
        $params = [];

        if ($request->maxTokens() !== null) {
            $params['max_tokens'] = $request->maxTokens();
            $params['max_length'] = $request->maxTokens(); // Some models use max_length
        }

        if ($request->temperature() !== null) {
            $params['temperature'] = $request->temperature();
        }

        if ($request->topP() !== null) {
            $params['top_p'] = $request->topP();
        }

        // Add any provider-specific options
        $providerOptions = $request->providerOptions();
        if (! empty($providerOptions)) {
            return array_merge($params, $providerOptions);
        }

        return $params;
    }

    /**
     * Extract text from Replicate output.
     * Replicate outputs can be strings, arrays, or objects.
     */
    protected function extractTextFromOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            // If it's an array of strings, join them
            if (isset($output[0]) && is_string($output[0])) {
                return implode('', $output);
            }

            // If it has a 'text' or 'output' key
            if (isset($output['text'])) {
                return (string) $output['text'];
            }

            if (isset($output['output'])) {
                return $this->extractTextFromOutput($output['output']);
            }
        }

        return '';
    }
}
