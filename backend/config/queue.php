<?php

return [
    'default' => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        'beanstalkd' => ['driver' => 'beanstalkd', 'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'), 'queue' => 'default', 'retry_after' => 90, 'block_for' => 0],
        'sqs' => ['driver' => 'sqs', 'key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'), 'queue' => env('SQS_QUEUE', 'default'), 'suffix' => env('SQS_SUFFIX'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
        ],
    ],
    'failed' => ['driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'), 'database' => env('DB_CONNECTION', 'sqlite'), 'table' => 'failed_jobs'],
];
