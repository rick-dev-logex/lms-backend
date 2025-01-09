<?php

return [

    /*
    |----------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |----------------------------------------------------------------------
    |
    | Aquí puedes configurar tus ajustes para el CORS. Esto determina qué
    | operaciones de origen cruzado pueden ejecutarse en los navegadores web.
    | para que aprendas un poco más de CORS, entra aquí: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],  // Permitir solo rutas API y la cookie CSRF

    'allowed_methods' => ['*'],  // Permitir todos los métodos HTTP (GET, POST, PUT, DELETE, etc.)

    'allowed_origins' => ['*'],  // Permitir todos los orígenes (frontend puede estar en cualquier URL)

    'allowed_headers' => ['*'],  // Permitir todos los encabezados

    'supports_credentials' => true,  // Si necesitas soportar credenciales (cookies, sesiones)

];
