<?php

return [
    // JWT Settings - all from .env
    'jwt' => [
        'secret'            => env('JWT_SECRET', 'change-this-secret-in-env-file'),
        'algorithm'         => 'HS256',
        'access_token_ttl'  => (int) env('JWT_ACCESS_TTL', 900),
        'refresh_token_ttl' => (int) env('JWT_REFRESH_TTL', 604800),
        'issuer'            => env('JWT_ISSUER', 'clinic-api'),
    ],

    // AES-256-CBC Encryption - key from .env
    'encryption' => [
        'key'    => env('ENCRYPTION_KEY', 'change-this-key-in-env-file-32ch'),
        'cipher' => 'aes-256-cbc',
    ],

    // CSRF Settings - TTL from .env
    'csrf' => [
        'token_ttl'   => (int) env('CSRF_TTL', 3600),
        'header_name' => 'X-CSRF-TOKEN',
    ],

    // Refresh Token Cookie - from .env
    'refresh_cookie' => [
        'name'     => env('REFRESH_COOKIE_NAME', 'refresh_token'),
        'httponly'  => true,
        'secure'   => (bool) env('REFRESH_COOKIE_SECURE', false),
        'samesite' => env('REFRESH_COOKIE_SAMESITE', 'Strict'),
        'path'     => env('REFRESH_COOKIE_PATH', '/api/auth'),
    ],
];