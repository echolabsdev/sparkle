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
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        ],
        'google' => [
            'driver' => 'google',
            'base_url' => env('GOOGLE_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('GOOGLE_API_KEY'),
        ],
        'ollama' => [
            'driver' => 'openai',
            'url' => env('OLLAMA_URL', 'http://localhost:11434/v1'),
        ],
    ],
];
