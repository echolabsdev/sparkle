<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAgentResponse;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsFinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Providers\Perplexity\Concerns\HandlesHttpRequests;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;

class Text
{
    use ExtractsAdditionalContent;
    use ExtractsAgentResponse;
    use ExtractsFinishReason;
    use ExtractsMeta;
    use ExtractsUsage;
    use HandlesHttpRequests;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(Request $request): TextResponse
    {
        $response = $this->sendRequest($this->client, $request);
        $data = $response->json();

        // Before anything is read: a failed or cancelled run arrives as HTTP
        // 200, so the transport status proves nothing on its own.
        $this->assertRunSucceeded($data);

        return new TextResponse(
            steps: collect(),
            text: $this->extractsText($data),
            finishReason: $this->extractsFinishReason($data),
            toolCalls: [],
            toolResults: [],
            usage: $this->extractUsage($data),
            meta: $this->extractsMeta($data),
            messages: collect($request->messages()),
            additionalContent: $this->extractsAdditionalContent($data),
        );
    }
}
