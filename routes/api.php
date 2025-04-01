<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\RequestController;
use App\Http\Controllers\API\ResponsibleController;
use App\Http\Controllers\API\TransportController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AreaController;
use App\Http\Controllers\API\LoanController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\ReposicionController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\ThirdPartyAppController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestMailController;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:web')->post('/logout', [AuthController::class, 'logout']);
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:web');

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/download-discounts-template', [TemplateController::class, 'downloadDiscountsTemplate']);
Route::get('/download-expenses-template', [TemplateController::class, 'downloadExpensesTemplate']);

Route::get('/debug', function () {
    return response()->json([
        'scheme' => request()->getScheme(),
        'secure' => request()->secure(),
        'headers' => request()->headers->all()
    ]);
});

Route::get('/test-email', [TestMailController::class, 'sendTestEmail']);

// Rutas protegidas por Sanctum
Route::middleware('auth:web')->group(function () {
    Route::apiResource('/accounts', AccountController::class);
    Route::apiResource('/responsibles', ResponsibleController::class);
    Route::apiResource('/transports', TransportController::class);
    Route::get('/vehicles', [TransportController::class, 'index']);
    Route::get('/transports/by-account/{accountId}', [TransportController::class, 'getTransportByAccountId']);

    Route::prefix('projects')->group(function () {
        Route::apiResource('/', ProjectController::class);
        Route::get('/{project}/users', [ProjectController::class, 'getProjectUsers']);
        Route::post('/{project}/users', [ProjectController::class, 'assignUsers']);
    });

    Route::apiResource('/areas', AreaController::class);
    Route::apiResource('/requests', RequestController::class);
    Route::post('/requests/import', [RequestController::class, 'import']);
    Route::post('/requests/upload-discounts', [RequestController::class, 'uploadDiscounts']);

    Route::apiResource('/reposiciones', ReposicionController::class)->except('file');
    Route::get('/reposiciones/{reposicion}/file', [ReposicionController::class, 'file']);

    Route::apiResource('/loans', LoanController::class);
    Route::get('/loans/{loan}/file', [LoanController::class, 'file']);

    // Rutas solo para administradores

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}', [UserController::class, 'update']);
        Route::delete('/{user}', [UserController::class, 'destroy']);
        Route::put('/{user}/permissions', [UserController::class, 'updatePermissions']);
        Route::get('/{user}/projects', [UserController::class, 'getUserProjects']);
        Route::post('/{user}/projects', [UserController::class, 'assignProjects']);
    });

    Route::apiResource('/roles', RoleController::class);
    Route::get('/roles/{role}/permissions', [RoleController::class, 'permissions']);
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions']);

    Route::apiResource('/permissions', PermissionController::class);
    Route::post('/permissions/{permission}/assign-to-role', [PermissionController::class, 'assignToRole']);

    Route::post('/register', [AuthController::class, 'register']);
});

// Captura de OPTIONS para CORS
Route::options('{any}', fn() => response('', 200))->where('any', '.*');
