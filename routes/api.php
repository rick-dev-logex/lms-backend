<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PersonalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Rutas de autenticación
Route::post('login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/user', [AuthController::class, 'user']);

// Este middleware permite acceso a las rutas únicamente a usuarios autenticados, 
// es decir, si no es todo, la mayoría de rutas deberían estar aquí dentro.

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    // Aquí pones más rutas o directamente resources si es que vamos a tener todos los métodos de una API RESTful.
    /*
     | 
     | Al implementar la aplicación en producción, deberías aprovechar el caché de rutas de Laravel. 
     | El uso del caché de rutas reducirá drásticamente la cantidad de tiempo que lleva registrar todas las rutas de la aplicación. 
     | Para generar un caché de rutas, ejecuta el comando route:cache de Artisan: php artisan route:cache
     | 
     | Después de ejecutar este comando, el archivo de rutas almacenadas en caché se cargará en cada solicitud. 
     | Recuerda que, si agregas nuevas rutas, deberás generar una nueva caché de rutas. 
     | Por este motivo, solo debes ejecutar el comando route:cache durante la implementación del proyecto.
     | 
     | Puedes usar el comando route:clear para borrar la caché de rutas: php artisan route:clear
     | 
     */
    Route::apiResource('personal', PersonalController::class); // utiliza apiResource, porque si utilizas resource, esto crea rutas create y edit que no nesecitamos. Son URLs más limpias para API REST.
});
