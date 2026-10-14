<?php

return [
    'default' => env('CACHE_STORE', 'file'),
    'stores' => [
        'array' => ['driver' => 'array'],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
        'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX', 'khwwc_'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'khwwc_'),
];
