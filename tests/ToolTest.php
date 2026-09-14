<?php

declare(strict_types=1);

namespace Tests;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool as ToolFacade;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;

it('can return tool details', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        });

    expect($searchTool->name())->toBe('search');
    expect($searchTool->description())->toBe('useful for searching current data');
    expect($searchTool->parametersAsArray())->toBe([
        'query' => [
            'description' => 'the search query',
            'type' => 'string',
        ],
    ]);

    expect($searchTool->requiredParameters())->toBe(['query']);
});

it('can use a closure', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';

        });

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('can be used via facade', function (): void {
    $searchTool = ToolFacade::as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';

        });

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('can use an invokeable', function (): void {
    $fn = new class
    {
        public function __invoke(string $query): string
        {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        }
    };

    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using($fn);

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('handles invokable subclass with using($this) without circular reference', function (): void {
    $tool = new class extends Tool
    {
        public function __construct()
        {
            parent::__construct();
            $this->as('test_tool')
                ->for('A test tool')
                ->withParameter(new StringSchema('query', 'the query'))
                ->using($this);
        }

        public function __invoke(string $query): string
        {
            return "Result: $query";
        }
    };

    expect($tool->handle(query: 'hello'))->toBe('Result: hello');
});

it('invokable subclass works without calling using() at all', function (): void {
    $tool = new class extends Tool
    {
        public function __construct()
        {
            parent::__construct();
            $this->as('auto_tool')
                ->for('Auto-detected invokable')
                ->withParameter(new StringSchema('input', 'the input'));
        }

        public function __invoke(string $input): string
        {
            return "Auto: $input";
        }
    };

    expect($tool->handle(input: 'test'))->toBe('Auto: test');
});

it('can have fluent parameters', function (): void {
    $tool = (new Tool)
        ->as('test tool')
        ->for('not really useful for anything')
        ->withStringParameter(name: 'query', description: 'the search query', required: false)
        ->withNumberParameter('age', 'the users age')
        ->withBooleanParameter('active', 'active status')
        ->withArrayParameter(
            name: 'items',
            description: 'user requested items',
            items: new StringSchema('itemm', 'an item that the user requested'),
        )
        ->withEnumParameter('status', 'the status', ['active', 'inactive'])
        ->withObjectParameter(
            name: 'user',
            description: 'the user object',
            properties: [
                new StringSchema('name', 'the users name'),
                new BooleanSchema('active_status', 'user active status'),
            ],
            requiredFields: [
                'name',
            ]
        );

    $keys = [
        'query',
        'age',
        'active',
        'items',
        'status',
        'user',
    ];

    expect($tool->parameters())->toHaveKeys($keys);

    collect($keys)->each(function ($key) use ($tool): void {
        expect($tool->parameters()[$key])->not->toBeEmpty();
    });

    expect($tool->requiredParameters())->not->toContain('query');
});

it('can throw a prism custom exception for invalid parameters', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        })
        ->withoutErrorHandling(); // Disable error handling to get exception

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid parameters for tool : search');

    $searchTool->handle([]);
});

it('can throw a prism custom exception for unknown named parameters', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        })
        ->withoutErrorHandling();

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid parameters for tool : search');

    $searchTool->handle(input: 'What time is the event?');
});

it('can throw a prism custom exception for invalid return type', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): int {
            expect($query)->toBe('What time is the event?');

            return 1;
        })
        ->withoutErrorHandling();

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid return type for tool : search. Tools must return string.');

    $searchTool->handle('What time is the event?');
});

enum ToolTestPriority: string
{
    case Low = 'low';
    case High = 'high';
}

enum ToolTestLevel: int
{
    case One = 1;
    case Two = 2;
}

it('coerces string arguments into declared scalar types', function (): void {
    $tool = (new Tool)
        ->as('transactions')
        ->for('fetches transactions')
        ->withNumberParameter('limit', 'max results')
        ->withBooleanParameter('include_pending', 'include pending')
        ->using(function (int $limit, bool $include_pending, float $ratio = 1.0): string {
            expect($limit)->toBe(5)
                ->and($include_pending)->toBeTrue()
                ->and($ratio)->toBe(0.5);

            return 'ok';
        });

    expect($tool->handle(limit: '5', include_pending: 'true', ratio: '0.5'))->toBe('ok');
});

it('converts string arguments to BackedEnum handler parameters', function (): void {
    $tool = (new Tool)
        ->as('prioritize')
        ->for('sets priority')
        ->withStringParameter('priority', 'the priority')
        ->using(function (ToolTestPriority $priority, ToolTestLevel $level = ToolTestLevel::One): string {
            expect($priority)->toBe(ToolTestPriority::High)
                ->and($level)->toBe(ToolTestLevel::Two);

            return 'ok';
        });

    expect($tool->handle(priority: 'high', level: '2'))->toBe('ok');
});

it('leaves unknown arguments to the existing validation handling', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('searches')
        ->withStringParameter('query', 'the query')
        ->using(fn (string $query): string => $query);

    $result = $tool->handle(query: 'hello', made_up_argument: 'noise');

    expect($result)->toBeInstanceOf(ToolError::class)
        ->and($result->message)->toContain('Parameter validation error');
});
