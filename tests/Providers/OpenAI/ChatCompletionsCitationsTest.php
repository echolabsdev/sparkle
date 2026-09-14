<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\OpenAI\Maps\ChatCompletionsCitationsMapper;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'sk-test-1234'));
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

describe('ChatCompletionsCitationsMapper', function (): void {
    it('maps citations with search results', function (): void {
        $result = ChatCompletionsCitationsMapper::map(
            ['https://example.com/1', 'https://example.com/2'],
            [
                ['title' => 'First Source', 'url' => 'https://example.com/1', 'date' => '2025-07-23', 'snippet' => 'First snippet', 'source' => 'web'],
                ['title' => 'Second Source', 'url' => 'https://example.com/2', 'snippet' => 'Second snippet', 'source' => 'web'],
            ]
        );

        expect($result)->not->toBeNull();
        expect($result->citations)->toHaveCount(2);

        expect($result->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($result->citations[0]->source)->toBe('https://example.com/1');
        expect($result->citations[0]->sourceTitle)->toBe('First Source');
        expect($result->citations[0]->sourceText)->toBe('First snippet');
        expect($result->citations[0]->additionalContent['date'])->toBe('2025-07-23');

        expect($result->citations[1]->sourceType)->toBe(CitationSourceType::Url);
        expect($result->citations[1]->source)->toBe('https://example.com/2');
        expect($result->citations[1]->sourceTitle)->toBe('Second Source');
    });

    it('maps citations without search results', function (): void {
        $result = ChatCompletionsCitationsMapper::map(
            ['https://example.com/1', 'https://example.com/2']
        );

        expect($result)->not->toBeNull();
        expect($result->citations)->toHaveCount(2);

        expect($result->citations[0]->source)->toBe('https://example.com/1');
        expect($result->citations[0]->sourceTitle)->toBeNull();
        expect($result->citations[0]->sourceText)->toBeNull();

        expect($result->citations[1]->source)->toBe('https://example.com/2');
    });

    it('returns null for empty citations array', function (): void {
        $result = ChatCompletionsCitationsMapper::map([]);

        expect($result)->toBeNull();
    });
});

describe('Stream with citations', function (): void {
    it('includes citations in stream end event', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-with-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'sonar')
            ->withPrompt('Search for something')
            ->asStream();

        $text = '';
        $lastEvent = null;

        foreach ($response as $event) {
            $lastEvent = $event;

            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        expect($text)->toContain('According to [1] and [2], the answer is clear.');

        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->not->toBeNull();
        expect($lastEvent->citations)->toHaveCount(1);

        $citationPart = $lastEvent->citations[0];
        expect($citationPart->citations)->toHaveCount(2);

        expect($citationPart->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($citationPart->citations[0]->source)->toBe('https://example.com/source1');
        expect($citationPart->citations[0]->sourceTitle)->toBe('Source One');
        expect($citationPart->citations[0]->sourceText)->toBe('First source snippet text');

        expect($citationPart->citations[1]->source)->toBe('https://example.com/source2');
        expect($citationPart->citations[1]->sourceTitle)->toBe('Source Two');
    });

    it('has null citations for streams without citation data', function (): void {
        FixtureResponse::fakeStreamResponses('chat/completions', 'openai-chat-completions/stream-basic-text');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt('Who are you?')
            ->asStream();

        $lastEvent = null;
        foreach ($response as $event) {
            $lastEvent = $event;
        }

        expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
        expect($lastEvent->citations)->toBeNull();
    });
});
