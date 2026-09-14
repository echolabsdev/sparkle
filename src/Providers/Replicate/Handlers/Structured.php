<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\Providers\Replicate\Maps\FinishReasonMap;
use Prism\Prism\Providers\Replicate\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use HandlesPredictions;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        // Build the prompt from messages with JSON instruction
        $prompt = $this->buildStructuredPrompt($request);

        // Prepare the prediction payload
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => array_merge(
                ['prompt' => $prompt],
                $this->buildInputParameters($request)
            ),
        ];

        // Create prediction and wait for completion (uses sync mode if enabled)
        $completedPrediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Extract and parse JSON output
        $text = $this->extractTextFromOutput($completedPrediction->output ?? []);
        $structured = $this->parseStructuredOutput($text, $request);

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $this->addStep($completedPrediction, $text, $structured, $request);

        return $this->responseBuilder->toResponse();
    }

    /**
     * Build prompt with JSON instructions based on mode.
     */
    protected function buildStructuredPrompt(Request $request): string
    {
        $basePrompt = MessageMap::map($request->messages());

        // Add JSON instruction based on mode
        $schemaJson = json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT);
        $jsonInstruction = match ($request->mode()) {
            StructuredMode::Json, StructuredMode::Auto => "\n\nRespond ONLY with valid JSON that matches this schema: ".$schemaJson,
            StructuredMode::Structured => "\n\nRespond ONLY with valid JSON that matches this schema: ".$schemaJson,
        };

        return $basePrompt.$jsonInstruction;
    }

    /**
     * Build input parameters from request.
     *
     * @return array<string, mixed>
     */
    protected function buildInputParameters(Request $request): array
    {
        $params = ['max_tokens' => 4096]; // Default for structured output

        // Map provider options
        foreach ($request->providerOptions() as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Extract text from prediction output.
     */
    protected function extractTextFromOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            return implode('', $output);
        }

        return '';
    }

    /**
     * Parse structured output based on mode.
     *
     * @return array<string, mixed>
     */
    protected function parseStructuredOutput(string $text, Request $request): array
    {
        // Try to extract JSON from the response
        $jsonMatch = [];
        if (preg_match('/\{.*\}/s', $text, $jsonMatch)) {
            $json = json_decode($jsonMatch[0], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        // If we can't parse JSON in structured modes, throw an exception
        if (in_array($request->mode(), [StructuredMode::Json, StructuredMode::Structured])) {
            throw new PrismException('Replicate: Failed to parse structured JSON output');
        }

        return [];
    }

    /**
     * Add step to response builder.
     *
     * @param  object{id: string, status: string, output: mixed, error: string|null, metrics: array<string, mixed>}  $prediction
     * @param  array<string, mixed>  $structured
     */
    protected function addStep(object $prediction, string $text, array $structured, Request $request): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: FinishReasonMap::map($prediction->status),
            usage: new Usage(
                promptTokens: $prediction->metrics['input_token_count'] ?? 0,
                completionTokens: $prediction->metrics['output_token_count'] ?? 0,
            ),
            meta: new Meta(
                id: $prediction->id,
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            structured: $structured,
        ));
    }

    /**
     * Extract version from model string.
     */
    /**
     * Extract version ID from model string.
     * Supports formats like "owner/model:version" or just "owner/model".
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Return as-is and let Replicate use the latest version or resolve the format
        return $model;
    }
}
