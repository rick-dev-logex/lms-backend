<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:3000'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'X-Requested-With',
        'Content-Type',
        'X-Token-Auth',
        'Authorization',
        'X-CSRF-TOKEN',
        'XSRF-TOKEN',
        'Accept',
    ],

    'exposed_headers' => false,

    'max_age' => 0,

    'supports_credentials' => true,
];
