<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'lms_backend' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'sgt.logex.com.ec'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'lms_backend'),
            'username' => env('DB_USERNAME', 'restrella'),
            'password' => env('DB_PASSWORD', 'LogeX-?2028*'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],
        'sistema_onix' => [ // Conexión adicional para sistema_onix
            'driver' => 'mysql',
            'host' => env('ONIX_DB_HOST', '127.0.0.1'),
            'port' => env('ONIX_DB_PORT', '3306'),
            'database' => env('ONIX_DB_DATABASE', 'sistema_onix'),
            'username' => env('ONIX_DB_USERNAME', 'root'),
            'password' => env('ONIX_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],
        'tms' => [ // Conexión adicional para tms
            'driver' => 'mysql',
            'host' => env('TMS_DB_HOST', '127.0.0.1'),
            'port' => env('TMS_DB_PORT', '3306'),
            'database' => env('TMS_DB_DATABASE', 'tms'),
            'username' => env('TMS_DB_USERNAME', 'root'),
            'password' => env('TMS_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
