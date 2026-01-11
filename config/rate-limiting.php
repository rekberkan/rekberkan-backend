<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits for different operations to prevent abuse.
    | Values are in requests per time window.
    |
    */

    'auth' => [
        'max_attempts' => env('RATE_LIMIT_AUTH', 10),
        'decay_minutes' => 10,
    ],

    'deposit' => [
        'max_attempts' => env('RATE_LIMIT_DEPOSIT', 10),
        'decay_minutes' => 60,
    ],

    'withdrawal' => [
        'max_attempts' => env('RATE_LIMIT_WITHDRAWAL', 5),
        'decay_minutes' => 60,
    ],

    'escrow' => [
        'max_attempts' => env('RATE_LIMIT_ESCROW', 20),
        'decay_minutes' => 60,
    ],

    'chat' => [
        'max_attempts' => env('RATE_LIMIT_CHAT', 120),
        'decay_minutes' => 60,
    ],

    'admin' => [
        'max_attempts' => env('RATE_LIMIT_ADMIN', 100),
        'decay_minutes' => 60,
    ],

    'webhook' => [
        'max_attempts' => 1000, // High limit for webhooks
        'decay_minutes' => 1,
    ],
];
