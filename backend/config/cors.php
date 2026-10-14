<?php

$origins = env('CORS_ALLOWED_ORIGINS', '*');
if ($origins === '*') {
    $origins = ['*'];
} else {
    $origins = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $origins) ?: []), fn ($value) => $value !== ''));
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Range', 'X-Total-Count'],
    'max_age' => 86400,
    'supports_credentials' => true,
];
