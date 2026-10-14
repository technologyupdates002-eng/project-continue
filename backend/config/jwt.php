<?php

return [
    'secret' => env('JWT_SECRET'),
    'iss' => env('JWT_ISSUER', 'khcww-welfare'),
    'ttl' => (int) env('JWT_TTL', 3600),
    'refresh_ttl' => (int) env('REFRESH_TTL', 2592000),
    'idle_timeout_minutes' => (int) env('JWT_IDLE_TIMEOUT_MINUTES', 10),
];
