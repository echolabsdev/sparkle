<?php

return [
    'prism_server' => [
        'enabled' => env('PRISM_SERVER_ENABLED', true),
    ],
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION', null),
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        ],
        'ollama' => [
            'driver' => 'openai',
            'url' => env('OLLAMA_URL', 'http://localhost:11434/v1'),
        ],
    ],
];
