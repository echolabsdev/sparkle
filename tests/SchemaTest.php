<?php

declare(strict_types=1);

namespace Tests;

use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

it('they can have nested properties', function (): void {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'a user object',
        properties: [
            new StringSchema('name', 'the users name'),
            new NumberSchema('age', 'the users age'),
            new EnumSchema(
                name: 'status',
                description: 'the users status',
                options: [
                    'active',
                    'inactive',
                    'suspended',
                ]
            ),
            new ArraySchema(
                name: 'hobbies',
                description: 'the users hobbies',
                items: new StringSchema('hobby', 'the users hobby')
            ),
            new ObjectSchema(
                name: 'address',
                description: 'the users address',
                properties: [
                    new StringSchema('street', 'the street part of the address'),
                    new StringSchema('city', 'the city part of the address'),
                    new StringSchema('country', 'the country part of the address'),
                    new NumberSchema('zip', 'the zip code part of the address'),
                ],
                requiredFields: ['street', 'city', 'country', 'zip']
            ),
        ]
    );

    expect($schema->toArray())->toBe([
        'description' => 'a user object',
        'type' => 'object',
        'properties' => [
            'name' => [
                'description' => 'the users name',
                'type' => 'string',
            ],
            'age' => [
                'description' => 'the users age',
                'type' => 'number',
            ],
            'status' => [
                'description' => 'the users status',
                'enum' => [
                    'active',
                    'inactive',
                    'suspended',
                ],
                'type' => 'string',
            ],
            'hobbies' => [
                'description' => 'the users hobbies',
                'type' => 'array',
                'items' => [
                    'description' => 'the users hobby',
                    'type' => 'string',
                ],
            ],
            'address' => [
                'description' => 'the users address',
                'type' => 'object',
                'properties' => [
                    'street' => [
                        'description' => 'the street part of the address',
                        'type' => 'string',
                    ],
                    'city' => [
                        'description' => 'the city part of the address',
                        'type' => 'string',
                    ],
                    'country' => [
                        'description' => 'the country part of the address',
                        'type' => 'string',
                    ],
                    'zip' => [
                        'description' => 'the zip code part of the address',
                        'type' => 'number',
                    ],
                ],
                'required' => ['street', 'city', 'country', 'zip'],
                'additionalProperties' => false,
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ]);
});

it('they can be nullable', function (): void {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'a user object',
        properties: [
            new StringSchema('name', 'the users name', nullable: true),
            new NumberSchema('age', 'the users age', nullable: true),
            new EnumSchema(
                name: 'status',
                description: 'the users status',
                options: [
                    'active',
                    'inactive',
                    'suspended',
                ],
                nullable: true
            ),
            new ArraySchema(
                name: 'hobbies',
                description: 'the users hobbies',
                items: new StringSchema('hobby', 'the users hobby'),
                nullable: true
            ),
            new BooleanSchema(name: 'is_admin', description: 'is an administrative user', nullable: true),
            new ObjectSchema(
                name: 'address',
                description: 'the users address',
                properties: [
                    new StringSchema('street', 'the street part of the address'),
                    new StringSchema('city', 'the city part of the address'),
                    new StringSchema('country', 'the country part of the address'),
                    new NumberSchema('zip', 'the zip code part of the address'),
                ],
                requiredFields: ['street', 'city', 'country', 'zip']
            ),
        ],
        nullable: true
    );

    expect($schema->toArray())->toBe([
        'description' => 'a user object',
        'type' => ['object', 'null'],
        'properties' => [
            'name' => [
                'description' => 'the users name',
                'type' => ['string', 'null'],
            ],
            'age' => [
                'description' => 'the users age',
                'type' => ['number', 'null'],
            ],
            'status' => [
                'description' => 'the users status',
                'enum' => [
                    'active',
                    'inactive',
                    'suspended',
                    null,
                ],
                'type' => ['string', 'null'],
            ],
            'hobbies' => [
                'description' => 'the users hobbies',
                'type' => ['array', 'null'],
                'items' => [
                    'description' => 'the users hobby',
                    'type' => 'string',
                ],
            ],
            'is_admin' => [
                'description' => 'is an administrative user',
                'type' => ['boolean', 'null'],
            ],
            'address' => [
                'description' => 'the users address',
                'type' => 'object',
                'properties' => [
                    'street' => [
                        'description' => 'the street part of the address',
                        'type' => 'string',
                    ],
                    'city' => [
                        'description' => 'the city part of the address',
                        'type' => 'string',
                    ],
                    'country' => [
                        'description' => 'the country part of the address',
                        'type' => 'string',
                    ],
                    'zip' => [
                        'description' => 'the zip code part of the address',
                        'type' => 'number',
                    ],
                ],
                'required' => ['street', 'city', 'country', 'zip'],
                'additionalProperties' => false,
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ]);
});

it('nullable enums include types', function (): void {
    $enumSchema = new EnumSchema(
        name: 'temp',
        description: 'sick or fever temp',
        options: [98.6, 100, 'unknown', 105],
        nullable: true
    );

    expect($enumSchema->toArray())->toBe([
        'description' => 'sick or fever temp',
        'enum' => [98.6, 100, 'unknown', 105, null],
        'type' => [
            'number',
            'string',
            'null',
        ],
    ]);
});

it('non-nullable enum with single type returns single type', function (): void {
    $enumSchema = new EnumSchema(
        name: 'user_type',
        description: 'the type of user',
        options: ['admin', 'super_admin', 'standard']
    );

    expect($enumSchema->toArray())->toBe([
        'description' => 'the type of user',
        'enum' => ['admin', 'super_admin', 'standard'],
        'type' => 'string',
    ]);
});

it('supports anyOf composition for OpenAI schemas', function (): void {
    $userSchema = new ObjectSchema(
        name: 'user',
        description: 'The user object to insert into the database',
        properties: [
            new StringSchema('name', 'The name of the user'),
            new NumberSchema('age', 'The age of the user'),
        ],
        requiredFields: ['name', 'age'],
        allowAdditionalProperties: false
    );

    $addressSchema = new ObjectSchema(
        name: 'address',
        description: 'The address object to insert into the database',
        properties: [
            new StringSchema('number', 'The number of the address. Eg. for 123 main st, this would be 123'),
            new StringSchema('street', 'The street name. Eg. for 123 main st, this would be main st'),
            new StringSchema('city', 'The city of the address'),
        ],
        requiredFields: ['number', 'street', 'city'],
        allowAdditionalProperties: false
    );

    $itemSchema = new AnyOfSchema([
        $userSchema,
        $addressSchema,
    ]);

    $parentSchema = new ObjectSchema(
        name: 'root',
        description: 'The root schema that can contain either a user or an address',
        properties: [
            $itemSchema,
        ],
        requiredFields: ['item'],
        allowAdditionalProperties: false
    );

    $expected = [
        'description' => 'The root schema that can contain either a user or an address',
        'type' => 'object',
        'properties' => [
            'item' => [
                'anyOf' => [
                    [
                        'description' => 'The user object to insert into the database',
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'description' => 'The name of the user',
                                'type' => 'string',
                            ],
                            'age' => [
                                'description' => 'The age of the user',
                                'type' => 'number',
                            ],
                        ],
                        'required' => ['name', 'age'],
                        'additionalProperties' => false,
                    ],
                    [
                        'description' => 'The address object to insert into the database',
                        'type' => 'object',
                        'properties' => [
                            'number' => [
                                'description' => 'The number of the address. Eg. for 123 main st, this would be 123',
                                'type' => 'string',
                            ],
                            'street' => [
                                'description' => 'The street name. Eg. for 123 main st, this would be main st',
                                'type' => 'string',
                            ],
                            'city' => [
                                'description' => 'The city of the address',
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['number', 'street', 'city'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ],
        'required' => ['item'],
        'additionalProperties' => false,
    ];

    expect($parentSchema->toArray())->toBe($expected);
});

it('supports StringSchema format and pattern', function (): void {
    $schema = new StringSchema(
        name: 'email',
        description: 'User email',
        pattern: '^[^@\s]+@[^@\s]+\.[^@\s]+$',
        format: 'email'
    );
    $expected = [
        'description' => 'User email',
        'type' => 'string',
        'pattern' => '^[^@\s]+@[^@\s]+\.[^@\s]+$',
        'format' => 'email',
    ];

    expect($schema->toArray())->toBe($expected);
});

it('supports NumberSchema restrictions', function (): void {
    $schema = new NumberSchema(
        name: 'score',
        description: 'User score',
        multipleOf: 0.5,
        maximum: 10,
        exclusiveMaximum: 10,
        minimum: 0,
        exclusiveMinimum: 0
    );
    $expected = [
        'description' => 'User score',
        'type' => 'number',
        'multipleOf' => 0.5,
        'maximum' => 10.0,
        'exclusiveMaximum' => 10.0,
        'minimum' => 0.0,
        'exclusiveMinimum' => 0.0,
    ];
    expect($schema->toArray())->toBe($expected);
});

it('supports ArraySchema minItems and maxItems', function (): void {
    $schema = new ArraySchema(
        name: 'tags',
        description: 'User tags',
        items: new StringSchema('tag', 'A tag'),
        minItems: 1,
        maxItems: 5
    );
    $expected = [
        'description' => 'User tags',
        'type' => 'array',
        'items' => [
            'description' => 'A tag',
            'type' => 'string',
        ],
        'minItems' => 1,
        'maxItems' => 5,
    ];
    expect($schema->toArray())->toBe($expected);
});

it('allows an object schema without explicit properties', function (): void {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'a user object',
        properties: [],
        nullable: true
    );

    expect($schema->toArray())->toBe([
        'description' => 'a user object',
        'type' => ['object', 'null'],
        'required' => [],
        'additionalProperties' => false,
    ]);
});
