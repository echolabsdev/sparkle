<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));
});

describe('Fim completion', function (): void {
    it('can generate fim completion', function (): void {
        FixtureResponse::fakeResponseSequence('v1/fim/completions', 'mistral/fim-completion');

        $response = Prism::fim()
            ->using(Provider::Mistral, 'codestral-2405')
            ->withPrompt('def add(a, b):')
            ->withSuffix('    print("Done")')
            ->asText();

        expect($response->usage->promptTokens)->toBe(8);
        expect($response->usage->completionTokens)->toBe(91);
        expect($response->meta->id)->toBe('447e3e0d457e42e98248b5d2ef52a2a3');
        expect($response->meta->model)->toBe('codestral-2405');
        expect($response->text)->toBe('return a+b');
        expect($response->finishReason)->toBe(FinishReason::Stop);

        Http::assertSent(function (Request $request): true {
            $data = $request->data();

            expect($data['model'])->toBe('codestral-2405');
            expect($data['prompt'])->toBe('def add(a, b):');
            expect($data['suffix'])->toBe('    print("Done")');

            return true;
        });
    });

    it('sets the rate limits on meta', function (): void {
        $this->freezeTime(function (Carbon $time): void {
            $time = $time->toImmutable();

            FixtureResponse::fakeResponseSequence('v1/fim/completions', 'mistral/fim-completion', [
                'ratelimitbysize-limit' => 500000,
                'ratelimitbysize-remaining' => 499900,
                'ratelimitbysize-reset' => 28,
            ]);

            $response = Prism::fim()
                ->using(Provider::Mistral, 'codestral-2405')
                ->withPrompt('def mul(a, b):')
                ->asText();

            expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($response->meta->rateLimits[0]->name)->toEqual('tokens');
            expect($response->meta->rateLimits[0]->limit)->toEqual(500000);
            expect($response->meta->rateLimits[0]->remaining)->toEqual(499900);
            expect($response->meta->rateLimits[0]->resetsAt->equalTo($time->addSeconds(28)))->toBeTrue();
        });
    });
});
