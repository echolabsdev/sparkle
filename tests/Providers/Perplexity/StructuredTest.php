<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('sends the correct basic request structure', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-structured');

    $response = Prism::structured()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Summarize the top 10 posts on Hacker News today')
        ->withSchema(new ObjectSchema(
            name: 'summaries',
            description: 'The summaries of the top posts',
            properties: [
                new ArraySchema(
                    name: 'summaries',
                    description: 'A list of summaries',
                    items: new ObjectSchema(
                        name: 'summary',
                        description: 'A summary of a single post',
                        properties: [
                            new StringSchema('title', 'The title of the post'),
                            new StringSchema('url', 'The URL of the post'),
                            new StringSchema('summary', 'A brief summary of the post'),
                        ],
                        requiredFields: ['title', 'url', 'summary']
                    )
                ),
            ],
            requiredFields: ['summaries']
        ))
        ->asStructured();

    expect($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(746)
        ->and($response->usage->cacheWriteInputTokens)->toBeNull()
        ->and($response->usage->cacheReadInputTokens)->toBeNull()
        ->and($response->meta->id)->toBe('9d3d3b6a-26ac-40bc-b0d2-4d7a1292b04e')
        ->and($response->meta->model)->toBe('sonar')
        ->and($response->structured)->toBe([
            'summaries' => [
                [
                    'title' => 'The privacy nightmare of browser fingerprinting',
                    'url' => 'https://news.ycombinator.com/item?id=46016249',
                    'summary' => 'Discusses the serious privacy concerns raised by browser fingerprinting techniques that can uniquely identify users without consent, threatening user anonymity online.',
                ],
                [
                    'title' => 'Meta buried \'causal\' evidence of social media harm, US court filings allege',
                    'url' => 'https://news.ycombinator.com/item?id=46019817',
                    'summary' => 'Allegations claim that Meta hid causal evidence about the harmful impacts of social media on users, as revealed in recent US court documents, sparking debate about corporate transparency and user safety.',
                ],
                [
                    'title' => 'WorldGen – Text to Immersive 3D Worlds',
                    'url' => 'https://news.ycombinator.com/item?id=46018380',
                    'summary' => 'Introducing WorldGen, a tool that generates immersive 3D worlds from textual descriptions, leveraging AI to dramatically simplify 3D content creation processes.',
                ],
                [
                    'title' => 'The Mozilla Cycle, Part III: Mozilla Dies in Ignominy',
                    'url' => 'https://news.ycombinator.com/item?id=46017910',
                    'summary' => 'An opinion piece reflecting on the decline of Mozilla, analyzing how organizational and strategic decisions led to its diminished influence in the web ecosystem.',
                ],
                [
                    'title' => '\'The French people want to save us\': help pours in for glassmaker Duralex',
                    'url' => 'https://news.ycombinator.com/item?id=46015379',
                    'summary' => 'Describes a community-driven effort in France to save the iconic glassmaker Duralex, highlighting public support and national sentiment for preserving local industry.',
                ],
                [
                    'title' => 'RondoDox Exploits Unpatched XWiki Servers to Pull More Devices into Botnet',
                    'url' => 'https://thehackernews.com/2025/11/rondodox-exploits-unpatched-xwiki.html',
                    'summary' => 'Reports on the rapid exploitation surge of unpatched XWiki servers by the RondoDox botnet starting November 2025, used for launching massive DDoS attacks via HTTP, UDP, and TCP protocols.',
                ],
                [
                    'title' => 'FAWK: LLMs can write a language interpreter',
                    'url' => 'https://news.ycombinator.com/item?id=46003144',
                    'summary' => 'Community discussion on the capability of large language models (LLMs) to autonomously write language interpreters, with parallels drawn to earlier scripting languages and cluster availability.',
                ],
                [
                    'title' => 'Open Source Bot That Summarizes Top Hacker News Stories',
                    'url' => 'https://news.ycombinator.com/item?id=33748363',
                    'summary' => 'Announcement of HN Summary, an open-source bot that uses GPT-3 to summarize top Hacker News stories and shares them on Telegram, aiming to enhance content accessibility and experiment with language models.',
                ],
                [
                    'title' => 'HackYourNews – AI summaries of the top Hacker News stories',
                    'url' => 'https://news.ycombinator.com/item?id=37427127',
                    'summary' => 'Presentation of HackYourNews, a website leveraging GPT-3.5-turbo to provide AI-generated summaries of the top Hacker News stories and comments, simplifying news consumption and focus.',
                ],
                [
                    'title' => 'November 22nd, 2025 | The privacy nightmare of browser fingerprinting (Podcast)',
                    'url' => 'https://podcasts.apple.com/us/podcast/november-22nd-2025-the-privacy-nightmare-of/id1681571416?i=1000737965528',
                    'summary' => 'A podcast episode recapping the top Hacker News posts of the day, emphasizing browser fingerprinting’s privacy issues, Meta’s social media harm evidence, AI-driven 3D world generation, and other key topics.',
                ],
            ],
        ]);
});
