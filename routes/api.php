<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestMailController;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/refresh-token', [AuthController::class, 'refresh']);

// Route::get('/download-excel-template', [TemplateController::class, 'downloadTemplate']);
Route::apiResource('/projects', ProjectController::class);
Route::get('/test-email', [TestMailController::class, 'sendTestEmail']);
Route::apiResource('/accounts', AccountController::class);


// Rutas protegidas por autenticación
Route::middleware(['auth:sanctum', 'verify.jwt'])->group(function () {
    // Rutas generales para usuarios autenticados
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Route::apiResource('/projects', ProjectController::class);

    // Rutas para administración de usuarios (requiere permiso específico)
    Route::middleware(['permission:manage_users'])->group(function () {
        Route::apiResource('users', UserController::class);
    });


    // Rutas solo para administradores
    Route::middleware(['role:admin,developer'])->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::get('/permissions', [AuthController::class, 'getPermissions']);
    });

    // Rutas específicas por rol
    Route::middleware(['role:manager'])->group(function () {
        // Rutas específicas para managers
    });

    // Rutas que requieren múltiples permisos
    Route::middleware(['permission:view_reports,generate_reports'])->group(function () {
        // Rutas para reportes
        Route::get('/download-excel-template', [TemplateController::class, 'downloadTemplate']);
    });

    // Rutas que requieren rol específico Y permiso específico
    Route::middleware(['role:admin', 'permission:manage_system'])->group(function () {
        // Rutas de configuración del sistema
    });
});
