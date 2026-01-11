<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin IP Whitelist
    |--------------------------------------------------------------------------
    |
    | List of IP addresses or CIDR ranges allowed to access admin panel.
    | Leave empty to allow all IPs (not recommended for production).
    |
    */
    'admin_ip_whitelist' => array_filter(explode(',', env('ADMIN_IP_WHITELIST', '')));

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for CAPTCHA after failed login attempts.
    |
    */
    'captcha' => [
        'enabled' => env('CAPTCHA_ENABLED', true),
        'threshold' => env('CAPTCHA_THRESHOLD', 3), // Failed attempts before CAPTCHA
        'provider' => env('CAPTCHA_PROVIDER', 'recaptcha'), // recaptcha, hcaptcha
    ],

    /*
    |--------------------------------------------------------------------------
    | Breach Database Check
    |--------------------------------------------------------------------------
    |
    | Enable checking passwords against HaveIBeenPwned database.
    |
    */
    'hibp_enabled' => env('HIBP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | MFA Settings
    |--------------------------------------------------------------------------
    |
    | Multi-factor authentication requirements.
    |
    */
    'mfa' => [
        'admin_required' => env('MFA_ADMIN_REQUIRED', true),
        'user_optional' => env('MFA_USER_OPTIONAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geo-Blocking
    |--------------------------------------------------------------------------
    |
    | Restrict access based on geographic location.
    |
    */
    'geo_blocking' => [
        'enabled' => env('GEO_BLOCKING_ENABLED', true),
        'allowed_countries' => ['ID'], // Indonesia only
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration for various endpoints.
    |
    */
    'rate_limiting' => [
        'authentication' => [
            'max_attempts' => env('AUTH_RATE_LIMIT', 5),
            'decay_minutes' => env('AUTH_RATE_DECAY', 15),
        ],
        'financial' => [
            'max_attempts' => env('FINANCIAL_RATE_LIMIT', 10),
            'decay_minutes' => env('FINANCIAL_RATE_DECAY', 1),
        ],
    ],

];
