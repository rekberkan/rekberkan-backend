<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'sanitized' => env('MIDTRANS_SANITIZED', true),
        '3ds' => env('MIDTRANS_3DS', true),
        'ip_whitelist' => array_filter(explode(',', (string) env('MIDTRANS_IP_WHITELIST', ''))),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'public_key' => env('XENDIT_PUBLIC_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'is_production' => env('XENDIT_IS_PRODUCTION', false),
    ],

];
