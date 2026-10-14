<?php

declare(strict_types=1);

return [
    'environment' => env('COOP_ENVIRONMENT', 'sandbox'),

    'token_url' => env('COOP_TOKEN_URL', 'https://openapi.co-opbank.co.ke/token'),

    'base_url' => env('COOP_ENVIRONMENT') === 'production'
        ? env('COOP_PRODUCTION_BASE_URL', 'https://api.co-opbank.co.ke')
        : env('COOP_SANDBOX_BASE_URL', 'https://openapi.co-opbank.co.ke'),

    'consumer_key' => env('COOP_CONSUMER_KEY'),
    'consumer_secret' => env('COOP_CONSUMER_SECRET'),

    'account_number' => env('COOP_ACCOUNT_NUMBER'),
    'account_name' => env('COOP_ACCOUNT_NAME', 'KIRINYAGA HEALTH CARE'),

    'default_user_id' => env('COOP_USER_ID', 'KirinyagaHealthCare'),
    'operator_code' => env('COOP_OPERATOR_CODE', 'KIRINYAGA'),

    'callback_base_url' => env('COOP_CALLBACK_BASE_URL', 'https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback'),

    'token_refresh_buffer' => (int) env('COOP_TOKEN_REFRESH_BUFFER', 60),

    'timeout' => (int) env('COOP_API_TIMEOUT', 30),
    'connect_timeout' => (int) env('COOP_CONNECT_TIMEOUT', 10),

    'enable_real_calls' => env('COOP_ENABLE_REAL_CALLS', false),

    'webhook_ips' => env('COOP_WEBHOOK_IPS', null),

    'verify_callback_signature' => env('COOP_CALLBACK_VERIFY_SIGNATURE', false),
    'callback_signature_secret' => env('COOP_CALLBACK_SIGNATURE_SECRET'),
];
