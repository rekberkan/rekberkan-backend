<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Secret
    |--------------------------------------------------------------------------
    */

    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT Keys (for RS256 algorithm)
    |--------------------------------------------------------------------------
    */

    'keys' => [
        'public' => env('JWT_PUBLIC_KEY'),
        'private' => env('JWT_PRIVATE_KEY'),
        'passphrase' => env('JWT_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT Time to Live
    |--------------------------------------------------------------------------
    |
    | Access token: Short-lived (15 minutes)
    | Refresh token: Long-lived (7 days) with rotation
    */

    'ttl' => env('JWT_TTL', 15), // minutes

    'refresh_ttl' => env('JWT_REFRESH_TTL', 10080), // 7 days in minutes

    /*
    |--------------------------------------------------------------------------
    | JWT Hashing Algorithm
    |--------------------------------------------------------------------------
    |
    | HS256: HMAC with SHA-256 (symmetric)
    | RS256: RSA with SHA-256 (asymmetric - recommended for production)
    */

    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Required Claims
    |--------------------------------------------------------------------------
    */

    'required_claims' => [
        'iss',
        'iat',
        'exp',
        'nbf',
        'sub',
        'jti',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Claims
    |--------------------------------------------------------------------------
    */

    'persistent_claims' => [
        'tenant_id',
        'device_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock Subject
    |--------------------------------------------------------------------------
    */

    'lock_subject' => true,

    /*
    |--------------------------------------------------------------------------
    | Leeway
    |--------------------------------------------------------------------------
    |
    | Clock skew tolerance in seconds
    */

    'leeway' => env('JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Blacklist
    |--------------------------------------------------------------------------
    */

    'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', true),

    'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'jwt' => Tymon\JWTAuth\Providers\JWT\Lcobucci::class,
        'auth' => Tymon\JWTAuth\Providers\Auth\Illuminate::class,
        'storage' => Tymon\JWTAuth\Providers\Storage\Illuminate::class,
    ],

];
