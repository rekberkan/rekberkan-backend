<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security-related settings for the application.
    |
    */

    'webhook_drift_seconds' => env('SECURITY_WEBHOOK_DRIFT_SECONDS', 300),

    'max_login_attempts' => env('SECURITY_MAX_LOGIN_ATTEMPTS', 5),

    'lockout_minutes' => env('SECURITY_LOCKOUT_MINUTES', 15),

    'password_min_length' => env('SECURITY_PASSWORD_MIN_LENGTH', 12),

    'jwt' => [
        'ttl' => env('JWT_TTL', 15), // minutes
        'refresh_ttl' => env('JWT_REFRESH_TTL', 43200), // minutes (30 days)
        'algo' => env('JWT_ALGO', 'RS256'),
    ],

];
