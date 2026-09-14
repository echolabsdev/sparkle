<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

it('Z provider handles structured request', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'z/structured-basic-response');

    $schema = new ObjectSchema(
        name: 'InterviewResponse',
        description: 'Structured response from AI interviewer',
        properties: [
            new StringSchema(
                name: 'message',
                description: 'The AI interviewer response message',
                nullable: false
            ),
            new EnumSchema(
                name: 'action',
                description: 'The next action to take in the interview',
                options: ['ask_question', 'ask_followup', 'ask_clarification', 'complete_interview'],
                nullable: false
            ),
            new EnumSchema(
                name: 'status',
                description: 'Current interview status',
                options: ['waiting_for_answer', 'question_asked', 'followup_asked', 'completed'],
                nullable: false
            ),
            new BooleanSchema(
                name: 'is_question',
                description: 'Whether this response contains a question',
                nullable: false
            ),
            new StringSchema(
                name: 'question_type',
                description: 'Type of question being asked',
                nullable: true
            ),
            new BooleanSchema(
                name: 'move_to_next_question',
                description: 'Whether to move to the next question after this response',
                nullable: false
            ),
        ],
        requiredFields: ['message', 'action', 'status', 'is_question', 'move_to_next_question']
    );

    $response = Prism::structured()
        ->using(Provider::Z, 'z-model')
        ->withSchema($schema)
        ->asStructured();

    $text = <<<'JSON_STRUCTURED'
        {"message":"That's a fantastic real-world application! Building a customer service chatbot with Laravel sounds like a great project that could really help streamline customer interactions. Let's shift gears a bit and talk about database optimization, which is crucial for backend performance. Have you had experience with database indexing, and can you share a situation where you needed to optimize database queries in a project?","action":"ask_question","status":"question_asked","is_question":true,"question_type":"database_optimization","move_to_next_question":true}
        JSON_STRUCTURED;

    expect($response->text)->toBe($text)
        ->and($response->structured)->toBe([
            'message' => "That's a fantastic real-world application! Building a customer service chatbot with Laravel sounds like a great project that could really help streamline customer interactions. Let's shift gears a bit and talk about database optimization, which is crucial for backend performance. Have you had experience with database indexing, and can you share a situation where you needed to optimize database queries in a project?",
            'action' => 'ask_question',
            'status' => 'question_asked',
            'is_question' => true,
            'question_type' => 'database_optimization',
            'move_to_next_question' => true,
        ])
        ->and($response->usage->promptTokens)->toBe(82)
        ->and($response->usage->cacheReadInputTokens)->toBe(1227)
        ->and($response->usage->completionTokens)->toBe(129)
        ->and($response->meta->id)->toBe('chatcmpl-123')
        ->and($response->meta->model)->toBe('z-model');
});
