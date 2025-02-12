<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'null'),
    'lifetime' => env('SESSION_LIFETIME', 120),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', true),
    'encrypt' => env('SESSION_ENCRYPT', false),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'lms_backend'), '_') . '_session'
    ),
    'path' => env('SESSION_PATH', '/'),
    // 'domain' => env('SESSION_DOMAIN', "lms-backend-898493889976.us-east1.run.app"),
    'domain' => env('SESSION_DOMAIN', '.lms.logex.com.ec'),
    'secure' => env('SESSION_SECURE_COOKIE', true),
    'http_only' => env('SESSION_HTTP_ONLY', false),
    'same_site' => 'none',
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),
];
