<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('generates text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt("How's the weather in southern Brazil?")
        ->asText();

    expect($response->usage->promptTokens)->toBe(8)
        ->and($response->usage->completionTokens)->toBe(346)
        ->and($response->usage->cacheWriteInputTokens)->toBeNull()
        ->and($response->usage->cacheReadInputTokens)->toBeNull()
        ->and($response->meta->id)->toBe('cf5283ea-1537-4b16-9c98-86fb223b203b')
        ->and($response->meta->model)->toBe('sonar')
        ->and($response->text)->toBe(
            "The weather in southern Brazil in mid-November 2025 is generally **warm and comfortable**, with average daytime temperatures around **24°C (75°F)** and nighttime lows around **18°C (64°F)**. It is springtime there, with pleasant conditions suitable for outdoor activities and light clothing[1][3].\n\nHowever, November also marks a period with relatively frequent rain, averaging about **15 rainy days** and **119 mm (4.7 inches) of precipitation** in the month, meaning around 50% of the days may have some rainfall. Rain tends to occur intermittently rather than continuous heavy storms[1][3].\n\nDaylight is about **13 hours** long, and the sea temperature in coastal areas is around **22°C (71 °F)**, cool but comfortable for swimming[1].\n\nWind conditions are moderate, with an average wind scale of 4 (on a general scale), and UV index can be high during sunny periods[1][4].\n\nIn summary:\n\n| Aspect           | Details                         |\n|------------------|--------------------------------|\n| Temperature      | ~24°C (75°F) day, ~18°C (64°F) night |\n| Rainfall         | About 15 rainy days; 119 mm (~4.7 in) monthly |\n| Daylight         | ~13 hours                      |\n| Sea temperature  | ~22°C (71°F)                   |\n| Wind             | Moderate (scale 4)             |\n| Weather type     | Warm, partly cloudy, intermittent rain, comfortable for outdoor activities |\n\nThus, southern Brazil in November is warm and partly rainy, with pleasant temperatures and a mix of sun and showers typical for the spring season[1][3][4]."
        );
});
