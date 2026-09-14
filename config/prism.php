<?php

return [
    'prism_server' => [
        // The middleware that will be applied to the Prism Server routes.
        'middleware' => [],
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],
    'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 30), // The timeout for requests in seconds.
    'telemetry' => [
        // Master switch. When false, telemetry is a complete no-op: no contexts
        // are minted and no events are dispatched.
        'enabled' => env('PRISM_TELEMETRY_ENABLED', false),

        // Include prompts, completions, and tool arguments in telemetry payloads.
        // Off by default: this data can contain PII. Only enable it where the
        // telemetry sink is trusted.
        'capture_content' => env('PRISM_TELEMETRY_CAPTURE_CONTENT', false),

        // Include attachment BYTES (images, documents, audio, video) in captured
        // content. Off by default: with it off, a media part keeps its kind, mime
        // type and file id, and its bytes are replaced by their size.
        'capture_media' => env('PRISM_TELEMETRY_CAPTURE_MEDIA', false),

        // Bound opt-in stream reconstruction so telemetry cannot turn an
        // otherwise streaming response into unbounded process memory.
        'content_max_length' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_LENGTH', 65_536),
        'content_max_items' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_ITEMS', 256),
    ],
    'providers' => [
        'openai' => [
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'organization' => env('OPENAI_ORGANIZATION', null),
            'project' => env('OPENAI_PROJECT', null),
            'api_format' => env('OPENAI_API_FORMAT', 'responses'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
            // Include beta strings as a comma separated list.
            'anthropic_beta' => env('ANTHROPIC_BETA', null),
        ],
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY', ''),
            'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY', ''),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],
        'xai' => [
            'api_key' => env('XAI_API_KEY', ''),
            'url' => env('XAI_URL', 'https://api.x.ai/v1'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', ''),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
        ],
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY', ''),
            'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1/'),
        ],
        'voyageai' => [
            'api_key' => env('VOYAGEAI_API_KEY', ''),
            'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY', ''),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'site' => [
                'http_referer' => env('OPENROUTER_SITE_HTTP_REFERER', null),
                'x_title' => env('OPENROUTER_SITE_X_TITLE', null),
            ],
        ],
        'replicate' => [
            'api_key' => env('REPLICATE_API_KEY', ''),
            'url' => env('REPLICATE_URL', 'https://api.replicate.com/v1'),
            'webhook_url' => env('REPLICATE_WEBHOOK_URL', null),
            'use_sync_mode' => env('REPLICATE_USE_SYNC_MODE', true), // Use Prefer: wait header
            'polling_interval' => env('REPLICATE_POLLING_INTERVAL', 1000),
            'max_wait_time' => env('REPLICATE_MAX_WAIT_TIME', 60),
            // Hosts generated images may be auto-downloaded from (https only).
            'download_hosts' => ['replicate.delivery'],
        ],
        'qwen' => [
            'api_key' => env('QWEN_API_KEY', ''),
            'url' => env('QWEN_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'),
        ],
        'azure' => [
            'url' => env('AZURE_AI_URL', ''),
            'api_key' => env('AZURE_AI_API_KEY', ''),
            'api_version' => env('AZURE_AI_API_VERSION', '2024-10-21'),
            'deployment_name' => env('AZURE_AI_DEPLOYMENT', ''),
        ],
        'requesty' => [
            'api_key' => env('REQUESTY_API_KEY', ''),
            'url' => env('REQUESTY_URL', 'https://router.requesty.ai/v1'),
            'site' => [
                'http_referer' => env('REQUESTY_SITE_HTTP_REFERER', null),
                'x_title' => env('REQUESTY_SITE_X_TITLE', null),
            ],
        ],
        'perplexity' => [
            'api_key' => env('PERPLEXITY_API_KEY', ''),
            'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
        ],
        'vertex' => [
            'project_id' => env('VERTEX_PROJECT_ID', ''),
            'region' => env('VERTEX_REGION', 'us-central1'),
            'access_token' => env('VERTEX_ACCESS_TOKEN', null),
            'credentials_path' => env('VERTEX_CREDENTIALS_PATH', null),
        ],
        'z' => [
            'url' => env('Z_URL', 'https://api.z.ai/api/paas/v4'),
            'api_key' => env('Z_API_KEY', ''),
        ],
    ],
];
