<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\RequestController;
use App\Http\Controllers\API\ResponsibleController;
use App\Http\Controllers\API\TransportController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AreaController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\ReposicionController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestMailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:6,1', 'cors'])->group(function () {
    // Rutas públicas
    Route::post('/login', [AuthController::class, 'login']);
});

// Route::middleware(['cors'])->group(function () {
//     // Rutas públicas
//     Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
//     Route::post('/reset-password', [AuthController::class, 'resetPassword']);
//     Route::get('/test-email', [TestMailController::class, 'sendTestEmail']);

//     Route::get('/download-excel-template', [TemplateController::class, 'downloadTemplate']);
//     Route::apiResource('/accounts', AccountController::class);
//     Route::apiResource('/transports', TransportController::class);
//     Route::apiResource('/responsibles', ResponsibleController::class);
//     Route::apiResource('/projects', ProjectController::class);
//     Route::apiResource('/requests', RequestController::class);
//     //Excel
//     Route::post('/requests/upload-discounts', [RequestController::class, 'uploadDiscounts']);


//     Route::apiResource('/reposiciones', ReposicionController::class);
//     Route::apiResource('/areas', AreaController::class);
//     Route::apiResource('/roles', RoleController::class);
//     Route::get('/roles/{role}/permissions', [RoleController::class, 'permissions']);
//     Route::apiResource('/permissions-list', PermissionController::class);

//     Route::post('/register', [AuthController::class, 'register']);
//     Route::apiResource('users', UserController::class);
//     Route::apiResource('/permissions', AuthController::class);
// });

Route::middleware(['auth:sanctum', 'cors'])->get('/test', function (Request $request) {
    return $request->user();
});

// Rutas protegidas por autenticación
Route::middleware(['verify.jwt', 'cors'])->group(function () {
    // Rutas generales para usuarios autenticados
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    // Rutas solo para administradores
    Route::middleware(['role:admin,developer'])->group(function () {
        // Route::post('/register', [AuthController::class, 'register']);
        // Route::apiResource('users', UserController::class);
        // Route::apiResource('/permissions', AuthController::class);
    });

    // Rutas para administración de usuarios (requiere permiso específico)
    Route::middleware(['permission:manage_users'])->group(function () {
        // Rutas para administración de usuarios
    });

    // Rutas específicas por rol
    Route::middleware(['role:manager'])->group(function () {
        // Rutas específicas para managers
    });

    // Rutas que requieren múltiples permisos
    Route::middleware(['permission:view_reports,generate_reports'])->group(function () {
        // Rutas para reportes
        Route::get('/download-excel-template', [TemplateController::class, 'downloadTemplate']);
        Route::apiResource(
            '/accounts',
            AccountController::class
        );
        Route::apiResource('/transports', TransportController::class);
        Route::apiResource('/responsibles', ResponsibleController::class);
        Route::apiResource(
            '/projects',
            ProjectController::class
        );
        Route::apiResource(
            '/requests',
            RequestController::class
        );
    });

    // Rutas que requieren rol específico Y permiso específico
    Route::middleware(['role:admin', 'permission:manage_system'])->group(function () {
        // Rutas de configuración del sistema
    });
});
