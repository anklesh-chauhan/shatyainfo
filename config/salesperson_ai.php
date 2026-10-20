<?php

return [
    /*
     * Enable only after configuring an API key, a model available to that API
     * account, and the comma-separated tenant IDs approved for external processing.
     * Standalone access is a separate opt-in for installations without tenancy.
     * Use a shared persistent cache store for request limits across app servers.
     * tenant_providers accepts optional tenant ID => openai|anthropic overrides.
     */
    'enabled' => env('SALESPERSON_AI_ENABLED', false),
    'provider' => env('SALESPERSON_AI_PROVIDER', 'openai'),
    'allowed_tenants' => array_filter(array_map('trim', explode(',', (string) env('SALESPERSON_AI_ALLOWED_TENANTS', '')))),
    'allow_standalone' => env('SALESPERSON_AI_ALLOW_STANDALONE', false),
    'tenant_providers' => [],
    'daily_requests' => (int) env('SALESPERSON_AI_DAILY_REQUESTS', 1000),
    'user_requests_per_minute' => (int) env('SALESPERSON_AI_USER_REQUESTS_PER_MINUTE', 10),
    'timeout_seconds' => 8,
    'max_input_bytes' => 12000,
    'max_output_tokens' => 512,
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL'),
        ],
    ],
];
