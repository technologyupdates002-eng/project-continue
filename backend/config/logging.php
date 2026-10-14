<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['single']],
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'debug')],
        'daily' => ['driver' => 'daily', 'path' => storage_path('logs/laravel.log'), 'level' => 'debug', 'days' => 14],
        'stderr' => ['driver' => 'monolog', 'handler' => Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'level' => 'debug'],
        'slow_queries' => ['driver' => 'single', 'path' => storage_path('logs/slow-queries.log'), 'level' => 'info'],
        'request_profile' => ['driver' => 'single', 'path' => storage_path('logs/request-profile.log'), 'level' => 'info'],
    ],
];
