<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Imports\LoanImport;
use App\Services\UniqueIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;

class LoanImportController extends Controller
{
    /**
     * Importa préstamos desde un archivo Excel
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        try {
            // Validación del archivo
            $request->validate([
                'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]);

            // Obtener el usuario desde el token JWT
            $jwtToken = $request->cookie('jwt-token');
            if (!$jwtToken) {
                throw new \Exception("No se encontró el token de autenticación.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;
            if (!$userId) {
                throw new \Exception("Usuario no identificado en el token JWT.");
            }

            $user = User::find($userId);
            if (!$user) {
                throw new \Exception("Usuario no encontrado.");
            }

            // Verificar que el usuario tenga permisos para los proyectos
            $assignedProjectIds = $this->getAssignedProjects($user);
            if (empty($assignedProjectIds)) {
                throw new \Exception("El usuario no tiene proyectos asignados.");
            }

            // Iniciar transacción de base de datos
            DB::beginTransaction();

            // Crear instancia del importador con el archivo
            $uniqueIdService = app(UniqueIdService::class);
            $importer = new LoanImport($request->file('excel_file'), $userId, $uniqueIdService);

            // Importar datos del Excel a la base de datos
            Excel::import($importer, $request->file('excel_file'));

            // Finalizar la importación (crear préstamos y reposiciones)
            $success = $importer->finalize();

            if (!$success) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Error al procesar la importación',
                    'errors' => $importer->errors
                ], 422);
            }

            DB::commit();

            // Devolver respuesta exitosa o con errores parciales
            if (count($importer->errors) > 0) {
                return response()->json([
                    'message' => 'Importación completada con advertencias',
                    'errors' => $importer->errors,
                    'success' => true
                ], 200);
            }

            return response()->json([
                'message' => 'Importación completada exitosamente',
                'success' => true
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en LoanImportController@import:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al procesar la importación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene los proyectos asignados al usuario (método copiado de LoanController)
     * 
     * @param User $user
     * @return array
     */
    private function getAssignedProjects($user)
    {
        $assignedProjectIds = [];
        if ($user && isset($user->assignedProjects)) {
            if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                $projectsValue = $user->assignedProjects->projects;
                $assignedProjectIds = is_string($projectsValue) ? json_decode($projectsValue, true) : $projectsValue;
            } elseif (is_array($user->assignedProjects)) {
                $assignedProjectIds = $user->assignedProjects;
            }
        }
        return array_map('strval', $assignedProjectIds ?: []);
    }
}
