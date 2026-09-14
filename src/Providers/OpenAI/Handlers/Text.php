<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsRequestBody;
use Prism\Prism\Providers\OpenAI\Concerns\ExtractsCitations;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\ProviderToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use BuildsRequestBody;
    use CallsTools;
    use ExtractsCitations;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    /** @var ?MessagePartWithCitations[] */
    protected ?array $citations = null;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $this->resolveToolApprovals($request);

        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $this->citations = $this->extractCitations($data);

        return match ($finishReason = $this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Length => throw new PrismException(sprintf(
                'OpenAI: max tokens exceeded (status: %s, type: %s). If using a reasoning model, increase max_tokens to account for internal reasoning token usage.',
                data_get($data, 'output.{last}.status', 'n/a'),
                data_get($data, 'output.{last}.type', 'n/a'),
            )),
            default => $this->handleStop($data, $request, $response),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $toolCalls = ToolCallMap::map(
            array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'function_call'),
            array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'reasoning'),
        );

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($data, $request, $clientResponse, $toolResults, $approvalRequests);

        $providerToolCalls = ProviderToolCallMap::map(data_get($data, 'output', []));

        $request->addMessage(new AssistantMessage(
            content: data_get($data, 'output.{last}.content.0.text') ?? '',
            toolCalls: $toolCalls,
            additionalContent: Arr::whereNotNull([
                'citations' => $this->citations,
                'provider_tool_calls' => $providerToolCalls === [] ? null : $providerToolCalls,
            ]),
            toolApprovalRequests: $approvalRequests,
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'responses',
            $this->buildRequestBody($request),
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        array $toolResults = [],
        array $toolApprovalRequests = []
    ): void {
        /** @var array<array-key, array<string, mixed>> $output */
        $output = data_get($data, 'output', []);

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(array_filter($output, fn (array $output): bool => $output['type'] === 'function_call')),
            toolResults: $toolResults,
            providerToolCalls: ProviderToolCallMap::map($output),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'usage.output_tokens_details.reasoning_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
                serviceTier: data_get($data, 'service_tier'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: Arr::whereNotNull([
                'citations' => $this->citations,
                'searchQueries' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'search')
                    ->map(fn (array $item): ?string => data_get($item, 'action.query'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'openPageUrls' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'open_page')
                    ->map(fn (array $item): ?string => data_get($item, 'action.url'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'findInPagePatterns' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'find_in_page')
                    ->map(fn (array $item): ?string => data_get($item, 'action.pattern'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'reasoningSummaries' => collect($output)
                    ->filter(fn (array $output): bool => $output['type'] === 'reasoning')
                    ->flatMap(fn (array $output): array => Arr::pluck($output['summary'] ?? [], 'text'))
                    ->filter()
                    ->toArray(),
                // A HOSTED IMAGE TOOL'S OUTPUT HAD NOWHERE TO GO. Every other
                // hosted tool above hands its result back here, and a turn that
                // generated an image got the bytes only inside `raw` — which is
                // the untyped escape hatch, not a surface. The asymmetry was the
                // gap, not the request parameters.
                //
                // The `result` is base64 with no data: prefix, so it is wrapped
                // in the same GeneratedImage the images endpoint returns. A
                // caller should not have to know which of the two produced it.
                'generatedImages' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'image_generation_call')
                    ->map(fn (array $item): ?GeneratedImage => is_string($item['result'] ?? null) && $item['result'] !== ''
                        ? new GeneratedImage(
                            base64: $item['result'],
                            // The provider rewrites the prompt and returns what
                            // it actually drew from. GeneratedImage already has
                            // the field, so a caller comparing the two does not
                            // have to cross-reference a second array.
                            revisedPrompt: is_string($item['revised_prompt'] ?? null) ? $item['revised_prompt'] : null,
                        )
                        : null)
                    ->filter()
                    ->values()
                    // ->all(), NOT ->toArray(). Collection::toArray() converts
                    // every Arrayable item it holds, and GeneratedImage is one
                    // (Media implements Arrayable) — so toArray() here silently
                    // handed back plain arrays and the typed value object never
                    // reached the caller. Nothing else in this block returns
                    // objects, which is why the difference has not bitten before.
                    ->all() ?: null,
                // Kept separate from the images themselves because it is the
                // part a caller reconciles against what it asked for — a
                // provider that silently served a different size or quality is
                // only visible here.
                'imageGenerationCalls' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'image_generation_call')
                    ->map(fn (array $item): array => Arr::whereNotNull([
                        'id' => $item['id'] ?? null,
                        'status' => $item['status'] ?? null,
                        'revised_prompt' => $item['revised_prompt'] ?? null,
                        'size' => $item['size'] ?? null,
                        'quality' => $item['quality'] ?? null,
                        'background' => $item['background'] ?? null,
                        'output_format' => $item['output_format'] ?? null,
                    ]))
                    ->values()
                    ->toArray() ?: null,
            ]),
            raw: $data,
            toolApprovalRequests: $toolApprovalRequests,
        ));
    }
}
