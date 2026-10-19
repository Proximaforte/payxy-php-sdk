<?php

declare(strict_types=1);

// Publish with: php artisan vendor:publish --tag=payxy-config
return [
    // pk_test_... (sandbox) or pk_live_... (production). Decides the API environment.
    'public_key' => env('PAYXY_PUBLIC_KEY'),

    // sk_test_... / sk_live_...: only used to verify webhook signatures, never sent to Payxy.
    'webhook_secret' => env('PAYXY_WEBHOOK_SECRET'),

    // Leave empty to use the key's environment (api.payxy.ng / api.payxy.africa).
    'base_url' => env('PAYXY_BASE_URL'),

    'timeout' => env('PAYXY_TIMEOUT', 30),
    'connect_timeout' => env('PAYXY_CONNECT_TIMEOUT', 10),

    // Only read-only calls (verify, getPaymentInfo) are retried; payments never are.
    'max_retries' => env('PAYXY_MAX_RETRIES', 2),

    // Log channel for request summaries (null = default channel). Bodies are never logged.
    'log_channel' => env('PAYXY_LOG_CHANNEL'),
];
