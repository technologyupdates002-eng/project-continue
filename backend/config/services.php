<?php

return [
    'abancool' => [
        'api_key' => env('ABANCOOL_API_KEY'),
        'sender_id' => env('ABANCOOL_SENDER_ID', 'KHCWWELDARE'),
        'base_url' => env('ABANCOOL_BASE_URL', 'https://api.abancool.tech/api/v1'),
    ],
    'talksasa' => [
        'api_key' => env('TALKSASA_API_KEY'),
        'sender_id' => env('TALKSASA_SENDER_ID', 'KHCWWELFARE'),
        'base_url' => env('TALKSASA_BASE_URL', 'https://bulksms.talksasa.com/api/v3'),
    ],
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'nvidia/nemotron-3-ultra-550b-a55b:free'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    ],
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'abancool'),
    ],
];
