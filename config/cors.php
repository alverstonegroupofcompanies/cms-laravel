<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        env('FRONTEND_URL', 'http://localhost:5173').',http://localhost:5173,http://127.0.0.1:5173'
    ))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins ?: ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    // Bearer token auth — no cookies needed across sites
    'supports_credentials' => false,
];
