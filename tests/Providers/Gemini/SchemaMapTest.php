<?php

declare(strict_types=1);

use Prism\Prism\Contracts\HasSchemaType;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Schema\StringSchema;

it('maps array schema correctly', function (): void {
    $map = (new SchemaMap(new ArraySchema(
        name: 'testArray',
        description: 'test array description',
        items: new StringSchema(
            name: 'testName',
            description: 'test string description',
            nullable: true,
        ),
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test array description',
        'type' => 'array',
        'items' => [
            'description' => 'test string description',
            'type' => 'string',
            'nullable' => true,
        ],
        'nullable' => true,
    ]);
});

it('maps boolean schema correctly', function (): void {
    $map = (new SchemaMap(new BooleanSchema(
        name: 'testBoolean',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test description',
        'type' => 'boolean',
        'nullable' => true,
    ]);
});

it('maps enum schema correctly', function (): void {
    $map = (new SchemaMap(new EnumSchema(
        name: 'testEnum',
        description: 'test description',
        options: ['option1', 'option2'],
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test description',
        'enum' => ['option1', 'option2'],
        'type' => 'string',
        'nullable' => true,
    ]);
});

it('maps number schema correctly', function (): void {
    $map = (new SchemaMap(new NumberSchema(
        name: 'testNumber',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test description',
        'type' => 'number',
        'nullable' => true,
    ]);
});

it('maps string schema correctly', function (): void {
    $map = (new SchemaMap(new StringSchema(
        name: 'testName',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test description',
        'type' => 'string',
        'nullable' => true,
    ]);
});

it('maps object schema correctly', function (): void {
    $map = (new SchemaMap(new ObjectSchema(
        name: 'testObject',
        description: 'test object description',
        properties: [
            new StringSchema(
                name: 'testName',
                description: 'test string description',
            ),
        ],
        requiredFields: ['testName'],
        allowAdditionalProperties: true,
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'description' => 'test object description',
        'type' => 'object',
        'properties' => [
            'testName' => [
                'description' => 'test string description',
                'type' => 'string',
            ],
        ],
        'required' => ['testName'],
        'nullable' => true,
    ]);
});

it('normalizes array-type nullable notation in properties to Gemini nullable format', function (): void {
    // Simulates schemas passed as raw arrays (e.g. via Laravel AI SDK's ObjectSchema
    // which uses Illuminate's JsonSchema serializer). That serializer produces
    // ["integer", "null"] for nullable fields — a valid JSON Schema draft-4 format
    // that Gemini does not support. Gemini requires "nullable": true instead.
    $schema = new class implements HasSchemaType, Schema
    {
        public function name(): string
        {
            return 'schema_definition';
        }

        public function schemaType(): string
        {
            return 'object';
        }

        public function toArray(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'videoViews' => ['type' => 'integer', 'description' => 'Total video views'],
                    'averageWatchTimeInSeconds' => ['type' => ['integer', 'null'], 'description' => 'Average watch time'],
                    'score' => ['type' => ['number', 'null'], 'description' => 'Score'],
                    'label' => ['type' => ['string', 'null'], 'description' => 'Label'],
                ],
                'required' => ['videoViews'],
            ];
        }
    };

    $map = (new SchemaMap($schema))->toArray();

    expect($map['properties']['videoViews'])->toBe([
        'type' => 'integer',
        'description' => 'Total video views',
    ]);

    expect($map['properties']['averageWatchTimeInSeconds'])->toBe([
        'type' => 'integer',
        'description' => 'Average watch time',
        'nullable' => true,
    ]);

    expect($map['properties']['score'])->toBe([
        'type' => 'number',
        'description' => 'Score',
        'nullable' => true,
    ]);

    expect($map['properties']['label'])->toBe([
        'type' => 'string',
        'description' => 'Label',
        'nullable' => true,
    ]);
});

it('does not map a raw schema', function (): void {
    $map = (new SchemaMap(new RawSchema(
        'schema',
        [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]
    )))->toArray();

    expect($map)->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'string',
        ],
    ]);
});
