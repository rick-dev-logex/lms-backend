<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\LoanImportController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\RequestController;
use App\Http\Controllers\API\ResponsibleController;
use App\Http\Controllers\API\StatsController;
use App\Http\Controllers\API\TransportController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AreaController;
use App\Http\Controllers\API\ComprobanteController;
use App\Http\Controllers\API\DocumentGenerationController;
use App\Http\Controllers\API\LoanController;
use App\Http\Controllers\API\MobileDataController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\ReposicionController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\SriConsultaController;
use App\Http\Controllers\API\SriContribuyenteController;
use App\Http\Controllers\API\SriDocumentController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:6,1'])->group(function () {
    // Rutas públicas con throttle para evitar brute force attacks
});

Route::prefix('mobile')
    ->withoutMiddleware(['api']) // Remove todos los middlewares del grupo 'api'
    ->middleware(\App\Http\Middleware\VerifyEndpointJWT::class) // Aplica solo VerifyEndpointJWT
    ->group(function () {
        Route::get('/data', [MobileDataController::class, 'index']);
    });
// Para actualizar la data subida previamente con UUIDs
Route::get('/update-data', [RequestController::class, 'updateRequestsData']);



Route::get('/debug', function () {
    return response()->json([
        'scheme' => request()->getScheme(),
        'secure' => request()->secure(),
        'headers' => request()->headers->all()
    ]);
});


Route::get('/serverstatus', function () {
    if (app()->isDownForMaintenance()) {
        return response()->json(["under_maintenance" => "true", "responseText" => "¡Estamos en mantenimiento!"], 503);
    }
    return response()->json(["under_maintenance" => "false"], 200);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/download-discounts-template', [TemplateController::class, 'downloadDiscountsTemplate'])
    ->withoutMiddleware([\App\Http\Middleware\ValidateApiToken::class])->middleware(\App\Http\Middleware\HandleCors::class);
// Descargar plantilla de excel descuentos y both
Route::get('/download-expenses-template', [TemplateController::class, 'downloadExpensesTemplate'])
    ->withoutMiddleware([\App\Http\Middleware\ValidateApiToken::class])->middleware(\App\Http\Middleware\HandleCors::class);
// Descargar plantilla de excel con cuentas solo de discount

// Rutas protegidas por autenticación
Route::middleware(['verify.jwt'])->group(function () {
    // Rutas generales para usuarios autenticados
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);
    Route::patch('/users/{user}', [UserController::class, 'patch']);


    // Rutas para documentos SRI
    Route::get('/sri-documents', [SriDocumentController::class, 'index']);
    Route::post('/generate-documents', [DocumentGenerationController::class, 'generate']);
    Route::patch('/sri-documents/batch', [SriDocumentController::class, 'batchUpdate']);
    Route::patch('/sri-documents/{id}', [SriDocumentController::class, 'update']);
    Route::get('/sri-documents/{id}', [SriDocumentController::class, 'show']);
    Route::get('/sri-documents/{id}/generate-xml', [SriDocumentController::class, 'generateXml']);
    Route::get('/sri-documents/{id}/generate-pdf', [SriDocumentController::class, 'generatePdf']);
    Route::get('/sri-documents-stats', [StatsController::class, 'index']);
    Route::post('/reports/generate', [ReportController::class, 'generate']);

    // Nuevas rutas para la integración con el SRI
    Route::prefix('sri')->group(function () {
        // Consultas de contribuyentes y comprobantes
        Route::post('/consultar-contribuyente', [SriConsultaController::class, 'consultarContribuyente']);
        Route::post('/consultar-comprobante', [SriConsultaController::class, 'consultarComprobante']);
        Route::post('/validar-comprobante', [SriConsultaController::class, 'validarComprobante']);
        Route::post('/obtener-info-desde-clave', [SriConsultaController::class, 'obtenerInfoDesdeClaveAcceso']);

        // Actualización de documentos con datos del SRI
        Route::post('/actualizar-documento-desde-ruc', [SriConsultaController::class, 'actualizarDocumentoDesdeRuc']);
        Route::post('/actualizar-documento-desde-clave', [SriConsultaController::class, 'actualizarDocumentoDesdeClaveAcceso']);
        Route::post('/actualizar-todos-documentos', [SriConsultaController::class, 'actualizarTodosDocumentos']);

        // Información específica para reportes
        // Route::get('/reportes/iva/{periodo}', [SriReporteController::class, 'reporteIva']);
        // Route::get('/reportes/retenciones/{periodo}', [SriReporteController::class, 'reporteRetenciones']);
        // Route::get('/reportes/contribuyentes/{periodo}', [SriReporteController::class, 'reporteContribuyentes']);
        // Route::get('/reportes/generar-ats/{periodo}', [SriReporteController::class, 'generarAts']);
    });
    Route::prefix('comprobantes')->group(function () {
        Route::post('/consultar', [ComprobanteController::class, 'consultarComprobante']);
        Route::post('/guardar', [ComprobanteController::class, 'guardarComprobante']);
        Route::post('/guardar-varios', [ComprobanteController::class, 'guardarVariosComprobantes']);
    });


    // Rutas para administración de contribuyentes
    Route::prefix('contribuyentes')->group(function () {
        Route::get('/', [SriContribuyenteController::class, 'getResumenContribuyentes']);
        Route::post('/actualizar', [SriContribuyenteController::class, 'actualizarContribuyentes']);
        Route::post('/validar-ruc', [SriContribuyenteController::class, 'validarRuc']);
        Route::post('/validar-autorizacion', [SriContribuyenteController::class, 'validarAutorizacion']);
    });



    Route::apiResource('/accounts', AccountController::class);
    Route::apiResource('/transports', TransportController::class);
    Route::apiResource('/responsibles', ResponsibleController::class);
    Route::get('/vehicles', [TransportController::class, 'index']);


    Route::prefix('projects')->group(function () {
        Route::apiResource('/', ProjectController::class);
        Route::get('/{id}/users', [ProjectController::class, 'getProjectUsers']);
        Route::post('/{id}/users', [ProjectController::class, 'assignUsers']);
    });

    // Route::get('/download-excel-template', [TemplateController::class, 'downloadTemplate']);
    Route::apiResource('/areas', AreaController::class);

    Route::apiResource('/requests', RequestController::class);
    Route::post('/requests/upload-discounts', [RequestController::class, 'uploadDiscounts']);

    // Generar reposiciones
    Route::apiResource('/reposiciones', ReposicionController::class)->except('file');
    Route::get('/reposiciones/{id}/file', [ReposicionController::class, 'file']);

    // Importar desde Excel
    Route::post('/requests/import', [RequestController::class, 'import']);

    // Eliminar mútiples
    Route::post('/requests/batch-delete', [RequestController::class, 'batchDelete']);
    
    // Rutas para importación de préstamos
    Route::post('/loans/import', [LoanImportController::class, 'import']);


    // Préstamos
    Route::apiResource('/loans', LoanController::class);

    // Rutas solo para administradores
    Route::middleware(['role:admin,developer'])->group(function () {
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::delete('/{user}', [UserController::class, 'destroy']);

            //Permisos
            Route::put('/{user}/permissions', [UserController::class, 'updatePermissions']);

            //Proyectos
            Route::get('/{user}/projects', [UserController::class, 'getUserProjects']);
            Route::post('/{user}/projects', [UserController::class, 'assignProjects']);
        });

        // Rutas de Roles
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('/', [RoleController::class, 'store']);
            Route::get('/{role}', [RoleController::class, 'show']);
            Route::put('/{role}', [RoleController::class, 'update']);
            Route::delete('/{role}', [RoleController::class, 'destroy']);
            Route::get('/{role}/permissions', [RoleController::class, 'permissions']);
            Route::put('/{role}/permissions', [RoleController::class, 'updatePermissions']);
        });

        // Rutas de Permisos
        Route::prefix('permissions')->group(function () {
            Route::get('/', [PermissionController::class, 'index']);
            Route::post('/', [PermissionController::class, 'store']);
            Route::get('/{permission}', [PermissionController::class, 'show']);
            Route::put('/{permission}', [PermissionController::class, 'update']);
            Route::delete('/{permission}', [PermissionController::class, 'destroy']);
            Route::post('/{permission}/assign-to-role', [PermissionController::class, 'assignToRole']);
        });

        Route::post('/register', [AuthController::class, 'register']);
    });

    // Rutas que requieren múltiples permisos
    Route::middleware(['permission:view_reports,generate_reports'])->group(function () {});

    // Rutas que requieren rol específico Y permiso específico
    Route::middleware(['role:admin', 'permission:manage_system'])->group(function () {
        // Rutas de configuración del sistema
    });
});

// Al final de api.php, captura todas las peticiones OPTIONS
Route::options('{any}', function () {
    return response('', 200);
})->where('any', '.*');
