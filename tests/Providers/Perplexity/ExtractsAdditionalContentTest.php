<?php

declare(strict_types=1);

use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAgentResponse;

it('extracts the reasoning correctly from the raw response', function (string $rawResponse, ?string $expectedOutput): void {
    $testClass = new class
    {
        use ExtractsAdditionalContent;

        public function testExtractsReasoning(string $rawResponse): ?string
        {
            return $this->extractsReasoning($rawResponse);
        }
    };

    $this->assertEquals($expectedOutput, (new $testClass)->testExtractsReasoning($rawResponse));
})->with([
    'with reasoning block' => [
        'rawResponse' => <<<'EOT'
            <think>
            The model reasoning process
            </think>

            { "foo": "bar" }
        EOT,
        'expectedOutput' => 'The model reasoning process',
    ],
    'without reasoning block' => [
        'rawResponse' => <<<'EOT'
            { "foo": "bar" }
        EOT,
        'expectedOutput' => null,
    ],
]);

it('extracts additional content from the agent output array', function (): void {
    $testClass = new class
    {
        use ExtractsAdditionalContent;
        use ExtractsAgentResponse;

        /**
         * @param  array<string, mixed>  $data
         * @return array<string, mixed>
         */
        public function testExtractsAdditionalContent(array $data): array
        {
            return $this->extractsAdditionalContent($data);
        }
    };

    // The Agent API returns one output item per step, each typed, rather than
    // choices[] with citations alongside.
    $responseData = [
        'status' => 'completed',
        'model' => 'google/gemini-3-pro',
        'output' => [
            [
                'type' => 'search_results',
                'results' => [
                    ['title' => 'Result 1', 'url' => 'https://example.com/1'],
                    ['title' => 'Result 2', 'url' => 'https://example.com/2'],
                ],
            ],
            [
                'type' => 'fetch_url_results',
                'results' => [
                    ['url' => 'https://example.com/fetched', 'content' => 'Fetched body'],
                ],
            ],
            [
                'type' => 'message',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => <<<'EOT'
                            <think>
                            The model reasoning process
                            </think>

                            { "foo": "bar" }
                        EOT,
                    ],
                ],
            ],
        ],
    ];

    $output = (new $testClass)->testExtractsAdditionalContent($responseData);

    expect($output['search_results'])->toHaveCount(2)
        ->and($output['search_results'][0]['url'])->toBe('https://example.com/1')
        ->and($output['fetch_url_results'])->toHaveCount(1)
        // Which model a preset actually resolved to — a preset can route to a
        // third party, and a token ledger needs to know which.
        ->and($output['resolved_model'])->toBe('google/gemini-3-pro')
        ->and($output['reasoning'])->toBe('The model reasoning process');
});

it('omits an empty source list rather than reporting it as a failure', function (): void {
    $testClass = new class
    {
        use ExtractsAdditionalContent;
        use ExtractsAgentResponse;

        /**
         * @param  array<string, mixed>  $data
         * @return array<string, mixed>
         */
        public function testExtractsAdditionalContent(array $data): array
        {
            return $this->extractsAdditionalContent($data);
        }
    };

    // Zero search results on a COMPLETED run is documented-normal: a preset may
    // answer without searching.
    $output = (new $testClass)->testExtractsAdditionalContent([
        'status' => 'completed',
        'model' => 'openai/gpt-5.1',
        'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Four.']]],
        ],
    ]);

    expect($output)->not->toHaveKey('search_results')
        ->and($output)->not->toHaveKey('fetch_url_results')
        ->and($output['resolved_model'])->toBe('openai/gpt-5.1');
});
