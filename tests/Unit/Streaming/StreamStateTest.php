<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

it('constructs with default empty state', function (): void {
    $state = new StreamState;

    expect($state->messageId())->toBe('')
        ->and($state->reasoningId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->provider())->toBe('')
        ->and($state->metadata())->toBeNull()
        ->and($state->hasStreamStarted())->toBeFalse()
        ->and($state->hasTextStarted())->toBeFalse()
        ->and($state->hasThinkingStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull()
        ->and($state->toolCalls())->toBe([])
        ->and($state->citations())->toBe([])
        ->and($state->usage())->toBeNull()
        ->and($state->finishReason())->toBeNull();
});

it('withMessageId returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withMessageId('msg-123');

    expect($result)->toBe($state)
        ->and($state->messageId())->toBe('msg-123');
});

it('withReasoningId returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withReasoningId('reason-456');

    expect($result)->toBe($state)
        ->and($state->reasoningId())->toBe('reason-456');
});

it('withModel returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withModel('gpt-4');

    expect($result)->toBe($state)
        ->and($state->model())->toBe('gpt-4');
});

it('withProvider returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withProvider('openai');

    expect($result)->toBe($state)
        ->and($state->provider())->toBe('openai');
});

it('withMetadata returns self and sets value', function (): void {
    $state = new StreamState;
    $metadata = ['temperature' => 0.7, 'max_tokens' => 100];

    $result = $state->withMetadata($metadata);

    expect($result)->toBe($state)
        ->and($state->metadata())->toBe($metadata);
});

it('withMetadata accepts null', function (): void {
    $state = new StreamState;
    $state->withMetadata(['foo' => 'bar']);

    $state->withMetadata(null);

    expect($state->metadata())->toBeNull();
});

it('markStreamStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markStreamStarted();

    expect($result)->toBe($state)
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('markTextStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markTextStarted();

    expect($result)->toBe($state)
        ->and($state->hasTextStarted())->toBeTrue();
});

it('markTextCompleted returns self and resets flag', function (): void {
    $state = new StreamState;
    $state->markTextStarted();

    $result = $state->markTextCompleted();

    expect($result)->toBe($state)
        ->and($state->hasTextStarted())->toBeFalse();
});

it('markThinkingStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markThinkingStarted();

    expect($result)->toBe($state)
        ->and($state->hasThinkingStarted())->toBeTrue();
});

it('supports fluent setter chaining', function (): void {
    $state = new StreamState;

    $result = $state
        ->withMessageId('msg-123')
        ->withModel('gpt-4')
        ->withProvider('openai')
        ->markStreamStarted();

    expect($result)->toBe($state)
        ->and($state->messageId())->toBe('msg-123')
        ->and($state->model())->toBe('gpt-4')
        ->and($state->provider())->toBe('openai')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('appendText accumulates text', function (): void {
    $state = new StreamState;

    $state->appendText('Hello');
    $state->appendText(' ');
    $state->appendText('world');

    expect($state->currentText())->toBe('Hello world');
});

it('appendText returns self', function (): void {
    $state = new StreamState;

    $result = $state->appendText('test');

    expect($result)->toBe($state);
});

it('appendThinking accumulates thinking', function (): void {
    $state = new StreamState;

    $state->appendThinking('First thought');
    $state->appendThinking(' and second');

    expect($state->currentThinking())->toBe('First thought and second');
});

it('appendThinking returns self', function (): void {
    $state = new StreamState;

    $result = $state->appendThinking('test');

    expect($result)->toBe($state);
});

it('withText replaces text', function (): void {
    $state = new StreamState;
    $state->appendText('Old text');

    $state->withText('New text');

    expect($state->currentText())->toBe('New text');
});

it('withText returns self', function (): void {
    $state = new StreamState;

    $result = $state->withText('test');

    expect($result)->toBe($state);
});

it('withThinking replaces thinking', function (): void {
    $state = new StreamState;
    $state->appendThinking('Old thinking');

    $state->withThinking('New thinking');

    expect($state->currentThinking())->toBe('New thinking');
});

it('withThinking returns self', function (): void {
    $state = new StreamState;

    $result = $state->withThinking('test');

    expect($result)->toBe($state);
});

it('withBlockContext sets index and type', function (): void {
    $state = new StreamState;

    $state->withBlockContext(2, 'text');

    expect($state->currentBlockIndex())->toBe(2)
        ->and($state->currentBlockType())->toBe('text');
});

it('withBlockContext returns self', function (): void {
    $state = new StreamState;

    $result = $state->withBlockContext(0, 'tool_use');

    expect($result)->toBe($state);
});

it('resetBlockContext clears index and type', function (): void {
    $state = new StreamState;
    $state->withBlockContext(5, 'thinking');

    $state->resetBlockContext();

    expect($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull();
});

it('resetBlockContext returns self', function (): void {
    $state = new StreamState;

    $result = $state->resetBlockContext();

    expect($result)->toBe($state);
});

it('addToolCall adds to specific index', function (): void {
    $state = new StreamState;
    $toolCall1 = ['id' => 'call-1', 'name' => 'search'];
    $toolCall2 = ['id' => 'call-2', 'name' => 'calculate'];

    $state->addToolCall(0, $toolCall1);
    $state->addToolCall(1, $toolCall2);

    expect($state->toolCalls())->toBe([
        0 => $toolCall1,
        1 => $toolCall2,
    ]);
});

it('addToolCall returns self', function (): void {
    $state = new StreamState;

    $result = $state->addToolCall(0, ['id' => 'test']);

    expect($result)->toBe($state);
});

it('addToolCall overwrites existing index', function (): void {
    $state = new StreamState;
    $state->addToolCall(0, ['id' => 'old']);

    $state->addToolCall(0, ['id' => 'new']);

    expect($state->toolCalls()[0])->toBe(['id' => 'new']);
});

it('appendToolCallInput creates new tool call if not exists', function (): void {
    $state = new StreamState;

    $state->appendToolCallInput(0, 'first chunk');

    expect($state->toolCalls())->toBe([
        0 => ['input' => 'first chunk'],
    ]);
});

it('appendToolCallInput appends to existing input', function (): void {
    $state = new StreamState;
    $state->appendToolCallInput(0, 'first');
    $state->appendToolCallInput(0, ' second');
    $state->appendToolCallInput(0, ' third');

    expect($state->toolCalls()[0]['input'])->toBe('first second third');
});

it('appendToolCallInput returns self', function (): void {
    $state = new StreamState;

    $result = $state->appendToolCallInput(0, 'test');

    expect($result)->toBe($state);
});

it('updateToolCall merges data into existing tool call', function (): void {
    $state = new StreamState;
    $state->addToolCall(0, ['id' => 'call-1', 'name' => 'search']);

    $state->updateToolCall(0, ['status' => 'complete', 'result' => 'found']);

    expect($state->toolCalls()[0])->toBe([
        'id' => 'call-1',
        'name' => 'search',
        'status' => 'complete',
        'result' => 'found',
    ]);
});

it('updateToolCall creates new tool call if not exists', function (): void {
    $state = new StreamState;

    $state->updateToolCall(0, ['id' => 'call-1']);

    expect($state->toolCalls())->toBe([
        0 => ['id' => 'call-1'],
    ]);
});

it('updateToolCall overwrites existing keys', function (): void {
    $state = new StreamState;
    $state->addToolCall(0, ['id' => 'old-id', 'name' => 'search']);

    $state->updateToolCall(0, ['id' => 'new-id']);

    expect($state->toolCalls()[0])->toBe([
        'id' => 'new-id',
        'name' => 'search',
    ]);
});

it('updateToolCall returns self', function (): void {
    $state = new StreamState;

    $result = $state->updateToolCall(0, ['test' => 'value']);

    expect($result)->toBe($state);
});

it('hasToolCalls returns false when empty', function (): void {
    $state = new StreamState;

    expect($state->hasToolCalls())->toBeFalse();
});

it('hasToolCalls returns true when tool calls exist', function (): void {
    $state = new StreamState;
    $state->addToolCall(0, ['id' => 'test']);

    expect($state->hasToolCalls())->toBeTrue();
});

it('addCitation adds MessagePartWithCitations', function (): void {
    $state = new StreamState;
    $citation1 = new MessagePartWithCitations('text 1');
    $citation2 = new MessagePartWithCitations('text 2');

    $state->addCitation($citation1);
    $state->addCitation($citation2);

    expect($state->citations())->toBe([$citation1, $citation2]);
});

it('addCitation returns self', function (): void {
    $state = new StreamState;
    $citation = new MessagePartWithCitations('test');

    $result = $state->addCitation($citation);

    expect($result)->toBe($state);
});

it('withUsage stores Usage object', function (): void {
    $state = new StreamState;
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50
    );

    $state->withUsage($usage);

    expect($state->usage())->toBe($usage)
        ->and($state->usage()->promptTokens)->toBe(100)
        ->and($state->usage()->completionTokens)->toBe(50);
});

it('withUsage returns self', function (): void {
    $state = new StreamState;
    $usage = new Usage(10, 5);

    $result = $state->withUsage($usage);

    expect($result)->toBe($state);
});

it('withFinishReason stores FinishReason enum', function (): void {
    $state = new StreamState;

    $state->withFinishReason(FinishReason::Stop);

    expect($state->finishReason())->toBe(FinishReason::Stop);
});

it('withFinishReason returns self', function (): void {
    $state = new StreamState;

    $result = $state->withFinishReason(FinishReason::Length);

    expect($result)->toBe($state);
});

it('shouldEmitStreamStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitStreamStart())->toBeTrue();
});

it('shouldEmitStreamStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markStreamStarted();

    expect($state->shouldEmitStreamStart())->toBeFalse();
});

it('shouldEmitTextStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitTextStart())->toBeTrue();
});

it('shouldEmitTextStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markTextStarted();

    expect($state->shouldEmitTextStart())->toBeFalse();
});

it('shouldEmitThinkingStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitThinkingStart())->toBeTrue();
});

it('shouldEmitThinkingStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markThinkingStarted();

    expect($state->shouldEmitThinkingStart())->toBeFalse();
});

it('reset clears all state', function (): void {
    $state = new StreamState;
    $state->withMessageId('msg-123')
        ->withReasoningId('reason-456')
        ->withModel('gpt-4')
        ->withProvider('openai')
        ->withMetadata(['key' => 'value'])
        ->markStreamStarted()
        ->markTextStarted()
        ->markThinkingStarted()
        ->appendText('some text')
        ->appendThinking('some thinking')
        ->withBlockContext(5, 'text')
        ->addToolCall(0, ['id' => 'test'])
        ->addCitation(new MessagePartWithCitations('citation'))
        ->withUsage(new Usage(100, 50))
        ->withFinishReason(FinishReason::Stop);

    $state->reset();

    expect($state->messageId())->toBe('')
        ->and($state->reasoningId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->provider())->toBe('')
        ->and($state->metadata())->toBeNull()
        ->and($state->hasStreamStarted())->toBeTrue()
        ->and($state->hasTextStarted())->toBeFalse()
        ->and($state->hasThinkingStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull()
        ->and($state->toolCalls())->toBe([])
        ->and($state->citations())->toBe([])
        ->and($state->usage())->not->toBeNull()
        ->and($state->finishReason())->not->toBeNull();
});

it('reset returns self', function (): void {
    $state = new StreamState;

    $result = $state->reset();

    expect($result)->toBe($state);
});

it('resetTextState clears text-related state only', function (): void {
    $state = new StreamState;
    $citation = new MessagePartWithCitations('test');
    $usage = new Usage(100, 50);

    $state->withMessageId('msg-123')
        ->withReasoningId('reason-456')
        ->withModel('gpt-4')
        ->withProvider('openai')
        ->markStreamStarted()
        ->markTextStarted()
        ->markThinkingStarted()
        ->appendText('some text')
        ->appendThinking('some thinking')
        ->addCitation($citation)
        ->withUsage($usage);

    $state->resetTextState();

    expect($state->messageId())->toBe('')
        ->and($state->hasTextStarted())->toBeFalse()
        ->and($state->hasThinkingStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->citations())->toBe([$citation])
        ->and($state->usage())->toBe($usage)
        ->and($state->model())->toBe('gpt-4')
        ->and($state->provider())->toBe('openai')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('resetTextState returns self', function (): void {
    $state = new StreamState;

    $result = $state->resetTextState();

    expect($result)->toBe($state);
});

it('resetBlock clears block context only', function (): void {
    $state = new StreamState;
    $state->withMessageId('msg-123')
        ->appendText('text')
        ->withBlockContext(5, 'text')
        ->addToolCall(0, ['id' => 'test']);

    $state->resetBlock();

    expect($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull()
        ->and($state->messageId())->toBe('msg-123')
        ->and($state->currentText())->toBe('text')
        ->and($state->hasToolCalls())->toBeTrue();
});

it('resetBlock returns self', function (): void {
    $state = new StreamState;

    $result = $state->resetBlock();

    expect($result)->toBe($state);
});

it('handles empty string values', function (): void {
    $state = new StreamState;

    $state->withMessageId('')
        ->withReasoningId('')
        ->withModel('')
        ->withProvider('')
        ->withText('')
        ->withThinking('');

    expect($state->messageId())->toBe('')
        ->and($state->reasoningId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->provider())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('');
});

it('handles empty array values', function (): void {
    $state = new StreamState;

    $state->withMetadata([]);

    expect($state->metadata())->toBe([])
        ->and($state->toolCalls())->toBe([])
        ->and($state->citations())->toBe([]);
});

it('handles complex Usage with all optional fields', function (): void {
    $state = new StreamState;
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5,
        cost: 0.0042,
    );

    $state->withUsage($usage);

    expect($state->usage())->toBe($usage)
        ->and($state->usage()->promptTokens)->toBe(100)
        ->and($state->usage()->completionTokens)->toBe(50)
        ->and($state->usage()->cacheWriteInputTokens)->toBe(25)
        ->and($state->usage()->cacheReadInputTokens)->toBe(10)
        ->and($state->usage()->thoughtTokens)->toBe(5)
        ->and($state->usage()->cost)->toBe(0.0042);
});

it('addUsage accumulates cost from multiple usages', function (): void {
    $state = new StreamState;

    $state->addUsage(new Usage(
        promptTokens: 50,
        completionTokens: 25,
        cost: 0.002,
    ));

    $state->addUsage(new Usage(
        promptTokens: 30,
        completionTokens: 15,
        cost: 0.001,
    ));

    expect($state->usage()->promptTokens)->toBe(80)
        ->and($state->usage()->completionTokens)->toBe(40)
        ->and($state->usage()->cost)->toBe(0.003);
});

it('addUsage keeps cost null when no usage has cost', function (): void {
    $state = new StreamState;

    $state->addUsage(new Usage(promptTokens: 50, completionTokens: 25));
    $state->addUsage(new Usage(promptTokens: 30, completionTokens: 15));

    expect($state->usage()->promptTokens)->toBe(80)
        ->and($state->usage()->completionTokens)->toBe(40)
        ->and($state->usage()->cost)->toBeNull();
});

it('addUsage treats null cost as zero when accumulating with non-null cost', function (): void {
    $state = new StreamState;

    $state->addUsage(new Usage(promptTokens: 50, completionTokens: 25, cost: 0.002));
    $state->addUsage(new Usage(promptTokens: 30, completionTokens: 15));

    expect($state->usage()->cost)->toBe(0.002);
});

it('handles MessagePartWithCitations with all fields', function (): void {
    $state = new StreamState;
    $citation = new MessagePartWithCitations(
        outputText: 'Some text',
        citations: [],
        additionalContent: ['source' => 'document.pdf']
    );

    $state->addCitation($citation);

    expect($state->citations()[0])->toBe($citation)
        ->and($state->citations()[0]->outputText)->toBe('Some text')
        ->and($state->citations()[0]->additionalContent)->toBe(['source' => 'document.pdf']);
});

it('handles all FinishReason enum values', function (): void {
    $state = new StreamState;

    $state->withFinishReason(FinishReason::Stop);
    expect($state->finishReason())->toBe(FinishReason::Stop);

    $state->withFinishReason(FinishReason::Length);
    expect($state->finishReason())->toBe(FinishReason::Length);

    $state->withFinishReason(FinishReason::ContentFilter);
    expect($state->finishReason())->toBe(FinishReason::ContentFilter);

    $state->withFinishReason(FinishReason::ToolCalls);
    expect($state->finishReason())->toBe(FinishReason::ToolCalls);

    $state->withFinishReason(FinishReason::Error);
    expect($state->finishReason())->toBe(FinishReason::Error);

    $state->withFinishReason(FinishReason::Other);
    expect($state->finishReason())->toBe(FinishReason::Other);

    $state->withFinishReason(FinishReason::Unknown);
    expect($state->finishReason())->toBe(FinishReason::Unknown);
});

it('preserves tool call indices correctly', function (): void {
    $state = new StreamState;

    $state->addToolCall(5, ['id' => 'call-5']);
    $state->addToolCall(2, ['id' => 'call-2']);
    $state->addToolCall(10, ['id' => 'call-10']);

    expect($state->toolCalls())->toBe([
        5 => ['id' => 'call-5'],
        2 => ['id' => 'call-2'],
        10 => ['id' => 'call-10'],
    ]);
});
