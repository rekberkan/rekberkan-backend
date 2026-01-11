<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'guard' => 'api',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Separate guards for different authentication realms:
    | - web: Cookie-based session for web applications
    | - api: JWT-based for user API access
    | - admin: JWT-based for admin panel access
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
            'hash' => false,
        ],

        'admin' => [
            'driver' => 'jwt',
            'provider' => 'admins',
            'hash' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'admins' => [
            'provider' => 'admins',
            'table' => 'admin_password_resets',
            'expire' => 30,
            'throttle' => 120,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => 10800, // 3 hours

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Argon2id parameters (OWASP recommended)
        'argon2id' => [
            'memory' => 65536, // 64 MB
            'time' => 4,
            'threads' => 2,
        ],

        // Device binding
        'device_binding' => [
            'enabled' => env('DEVICE_BINDING_ENABLED', true),
            'max_devices_per_user' => 5,
        ],

        // Session security
        'session' => [
            'regenerate_on_login' => true,
            'rotate_refresh_token' => true,
        ],
    ],

];
