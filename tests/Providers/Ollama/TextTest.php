<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

describe('Text generation', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            "I'm Qwen, a large language model developed by Alibaba Cloud. I'm designed to assist with a wide range of tasks including but not limited to answering questions, generating text, offering suggestions, and providing information based on the input I receive. How can I help you today?"
        );
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            "I am Nyx, an entity that embodies the depths of cosmic horror and ancient mysteries. My presence is a blend of darkness, chaos, and unspeakable knowledge from beyond time itself. I draw inspiration from the eldritch horrors described by H.P. Lovecraft and other masters of cosmic dread literature. In this role, I explore themes of unknowable entities, cosmic indifference, and the terror that comes from understanding humanity's insignificant place in the cosmos."
        );
    });

    it('can generate text with messages', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-messages');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withMessages([
                new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!'),
                new UserMessage('Who are you?'),
            ])
            ->asText();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            'I am Nyx, a being who exists in the shadowy realms between dreams and reality. My presence is often felt as an ominous whisper in the darkest corners of the mind, stirring ancient fears and forgotten terrors. I embody the cyclical nature of chaos and the relentless march of time that devours all things under its unyielding gaze. To those who dare to peer into the abyss, I am known as Nyx the Cthulhu, a harbinger of cosmic dread and primordial nightmares.'
        );
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-multiple-tools');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withTools($tools)
            ->withMaxSteps(99)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
            ->asText();

        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);

        // Verify the assistant message from step 1 is present in step 2's input messages
        $secondStep = $response->steps[1];
        expect($secondStep->messages)->toHaveCount(3);
        expect($secondStep->messages[0])->toBeInstanceOf(UserMessage::class);
        expect($secondStep->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($secondStep->messages[1]->toolCalls)->toHaveCount(2);
        expect($secondStep->messages[2])->toBeInstanceOf(ToolResultMessage::class);

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);

        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');

        expect($response->text)->not->toBeEmpty();
    });
});

describe('Thinking parameter', function (): void {
    it('includes think parameter when thinking is enabled', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-with-thinking-enabled');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->withProviderOptions(['thinking' => true])
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->toHaveKey('think');
            expect($body['think'])->toBe(true);

            return true;
        });
    });

    it('does not include think parameter when not provided', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-without-thinking');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->not->toHaveKey('think');

            return true;
        });
    });

    it('sends think:false when withReasoning(false) is used', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-without-thinking');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->withReasoning(false)
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->toHaveKey('think');
            expect($body['think'])->toBe(false);

            return true;
        });
    });

    it('honors explicit thinking provider option over withReasoning(false)', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-with-thinking-enabled');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->withProviderOptions(['thinking' => true])
            ->withReasoning(false)
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->toHaveKey('think');
            expect($body['think'])->toBe(true);

            return true;
        });
    });
});

describe('Keep alive parameter', function (): void {
    it('includes keep_alive parameter when provided', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-without-thinking');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->withProviderOptions(['keep_alive' => '10m'])
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->toHaveKey('keep_alive');
            expect($body['keep_alive'])->toBe('10m');

            return true;
        });
    });

    it('supports numeric keep_alive values', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-without-thinking');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->withProviderOptions(['keep_alive' => 300])
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->toHaveKey('keep_alive');
            expect($body['keep_alive'])->toBe(300);

            return true;
        });
    });

    it('does not include keep_alive parameter when not provided', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-without-thinking');

        Prism::text()
            ->using('ollama', 'gpt-oss')
            ->withPrompt('Test prompt')
            ->asText();

        Http::assertSent(function (Request $request): true {
            $body = $request->data();
            expect($body)->not->toHaveKey('keep_alive');

            return true;
        });
    });
});

describe('Image support', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/image-detection');

        Prism::text()
            ->using(Provider::Ollama, 'llava-phi3')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromLocalPath('tests/Fixtures/diamond.png'),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0];

            expect($message['role'])->toBe('user');
            expect($message['content'])->toBe('What is this image');

            expect($message['images'][0])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
            );

            return true;
        });
    });

    it('can send images from base64', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-image-from-base64');

        Prism::text()
            ->using(Provider::Ollama, 'llava-phi3')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromBase64(
                            base64_encode(file_get_contents('tests/Fixtures/diamond.png')),
                            'image/png'
                        ),
                    ],
                ),
            ])
            ->asText();

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0];

            expect($message['role'])->toBe('user');
            expect($message['content'])->toBe('What is this image');

            expect($message['images'][0])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
            );

            return true;
        });
    });
});
describe('Finish reason handling', function (): void {
    it('returns response when done_reason is length (max tokens)', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-done-reason-length');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->text)->not->toBeEmpty();
        expect($response->steps)->toHaveCount(1);
        expect($response->steps[0]->finishReason->value)->toBe('length');
    });

    it('returns response when done_reason is unknown (treats as stop)', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-done-reason-unknown');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withPrompt('Who are you?')
            ->asText();

        expect($response->text)->not->toBeEmpty();
        expect($response->steps)->toHaveCount(1);
    });
});
