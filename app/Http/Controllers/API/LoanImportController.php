<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Imports\LoanImport;
use App\Services\UniqueIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Maatwebsite\Excel\Facades\Excel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Google\Cloud\Storage\StorageClient;

class LoanImportController extends Controller
{
    private $storage;
    private $bucketName;
    private $uniqueIdService;

    public function __construct(UniqueIdService $uniqueIdService)
    {
        $this->uniqueIdService = $uniqueIdService;

        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
        if (!$base64Key) {
            throw new \Exception('La clave de Google Cloud no está definida en el archivo .env.');
        }
        $credentials = json_decode(base64_decode($base64Key), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
        }

        $this->storage = new StorageClient([
            'keyFile' => $credentials
        ]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'lms-archivos');
    }

    /**
     * Importa préstamos desde un archivo Excel
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        // Intentar aumentar el tiempo de ejecución
        try {
            ini_set('max_execution_time', 300); // 5 minutos
            set_time_limit(300);
        } catch (\Exception $e) {
            Log::warning("No se pudo extender el tiempo límite de ejecución");
        }

        Log::info('Inicio de importación: ' . now());

        try {
            // Determinar qué archivo vamos a usar
            $uploadedFile = $this->getUploadedFile($request);

            if (!$uploadedFile) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['No se ha enviado ningún archivo. Por favor, seleccione un archivo Excel para importar.']
                    ],
                    'debug_info' => [
                        'files' => $request->allFiles(),
                        'all' => $request->all(),
                    ]
                ], 422);
            }

            // Validar archivo
            $validationResult = $this->validateFile($uploadedFile);
            if (isset($validationResult['error'])) {
                return response()->json($validationResult['error'], $validationResult['code']);
            }

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

            // Verificar permisos para proyectos
            $assignedProjectIds = $this->getAssignedProjects($user);
            if (empty($assignedProjectIds)) {
                throw new \Exception("El usuario no tiene proyectos asignados.");
            }

            // Usar nuestro importador personalizado
            $importer = new LoanImport($uploadedFile, $userId, $this->uniqueIdService);

            // Ejecutar la importación
            $result = Excel::import($importer, $uploadedFile);

            // Finalizar y procesar resultados
            if ($importer->finalize()) {
                return response()->json([
                    'message' => 'Importación completada exitosamente',
                    'success' => true,
                    'requests_created' => count($importer->allRequestIds ?? []),
                    'warnings' => !empty($importer->errors) ? $importer->errors : null
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Error en la importación',
                    'errors' => $importer->errors
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error en LoanImportController@import:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al procesar la importación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene el archivo cargado
     * 
     * @param Request $request
     * @return \Illuminate\Http\UploadedFile|null
     */
    private function getUploadedFile(Request $request)
    {
        // Buscar el archivo en varios campos comunes
        $fileFields = ['file', 'excel_file', 'import_file', 'loan_file', 'document'];

        foreach ($fileFields as $field) {
            if ($request->hasFile($field)) {
                return $request->file($field);
            }
        }

        // Si no encontramos en campos específicos, buscamos en todos los archivos
        foreach ($request->allFiles() as $key => $file) {
            return $file; // Devolvemos el primer archivo que encontremos
        }

        return null;
    }

    /**
     * Valida el archivo subido
     * 
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validateFile($file)
    {
        if (!$file->isValid()) {
            return [
                'error' => [
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo subido no es válido.']
                    ],
                    'error_code' => $file->getError()
                ],
                'code' => 422
            ];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'])) {
            return [
                'error' => [
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo debe ser de tipo Excel (.xlsx, .xls) o CSV (.csv).']
                    ]
                ],
                'code' => 422
            ];
        }

        if ($file->getSize() > 10240 * 1024) {
            return [
                'error' => [
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo no debe ser mayor a 10MB.']
                    ]
                ],
                'code' => 422
            ];
        }

        return ['valid' => true];
    }

    /**
     * Obtiene los proyectos asignados al usuario
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
