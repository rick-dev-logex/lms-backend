<?php

return [
    'paths' => ['api/*', 'login', 'logout'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['https://lms.logex.com.ec'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
