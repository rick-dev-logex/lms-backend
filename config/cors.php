<?php

return [
    'paths' => ['api/*', 'login'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // 'allowed_origins' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'https://lms.logex.com.ec',
        'https://api.lms.logex.com.ec',
        'https://lms-backend-898493889976.us-east1.run.app'
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'Cookie',
        'Set-Cookie',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN'
    ],
    'exposed_headers' => ['Authorization', 'Content-Type', 'X-Request-With'],
    'max_age' => 0,
    'supports_credentials' => true,
];
