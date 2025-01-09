<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'], // Define qué rutas deben ser accesibles desde orígenes cruzados (cross-origin). En tu caso, se permite el acceso a todas las rutas API (api/*) y la ruta de CSRF para Sanctum (sanctum/csrf-cookie).

    'allowed_methods' => ['*'], // Especifica los métodos HTTP permitidos (como GET, POST, PUT, DELETE). El valor ['*'] indica que se permiten todos los métodos.

    'allowed_origins' => ['http://localhost:3000'], // Establece qué orígenes (dominios) pueden hacer solicitudes al servidor. Si pones ['*'], se permitirá que cualquier origen acceda a tus recursos. Sin embargo, es recomendable especificar nuestros dominios de logex (por ejemplo, el dominio del frontend en Next.js) para mayor seguridad. Aún no está publicado, así que lo mantenemos así.

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization'], // Determina qué encabezados pueden ser enviados por el cliente en la solicitud. El valor ['*'] permite cualquier encabezado. En este caso yo le puse permitir solo los encabezados necesarios

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // Si necesitas credenciales (cookies, autenticación)

];
