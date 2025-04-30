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
use App\Models\Account;
use App\Models\Request as RequestModel;
use App\Models\Loan;
use App\Models\Reposicion;
use Google\Cloud\Storage\StorageClient;
use Carbon\Carbon;

class LoanImportController extends Controller
{
    private $storage;
    private $bucketName;

    public function __construct()
    {
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
        try {
            // Registrar todos los datos recibidos para debug
            Log::info('Recibiendo solicitud de importación', [
                'has_file' => $request->hasFile('file'),
                'has_excel_file' => $request->hasFile('excel_file'),
                'all_files' => array_keys($request->allFiles()),
                'all_inputs' => $request->all(),
                'content_type' => $request->header('Content-Type')
            ]);

            // Determinar qué archivo vamos a usar
            $uploadedFile = null;

            if ($request->hasFile('file')) {
                $uploadedFile = $request->file('file');
            } elseif ($request->hasFile('excel_file')) {
                $uploadedFile = $request->file('excel_file');
            } elseif ($request->hasFile('import_file')) {
                $uploadedFile = $request->file('import_file');
            } else {
                // Intentar detectar cualquier archivo en la solicitud
                foreach ($request->allFiles() as $key => $file) {
                    $uploadedFile = $file;
                    break;
                }
            }

            // Si no se encontró ningún archivo, devolver error
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

            // Validar que el archivo esté bien
            if (!$uploadedFile->isValid()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo subido no es válido.']
                    ],
                    'error_code' => $uploadedFile->getError()
                ], 422);
            }

            // Validar el archivo
            if (!in_array($uploadedFile->getClientOriginalExtension(), ['xlsx', 'xls', 'csv'])) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo debe ser de tipo Excel (.xlsx, .xls) o CSV (.csv).']
                    ]
                ], 422);
            }

            if ($uploadedFile->getSize() > 10240 * 1024) { // 10MB en bytes
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'file' => ['El archivo no debe ser mayor a 10MB.']
                    ]
                ], 422);
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

            // Verificar que el usuario tenga permisos para los proyectos
            $assignedProjectIds = $this->getAssignedProjects($user);
            if (empty($assignedProjectIds)) {
                throw new \Exception("El usuario no tiene proyectos asignados.");
            }

            // Guardar el archivo en una ubicación temporal más segura
            $tempPath = sys_get_temp_dir() . '/' . uniqid('import_', true) . '.' . $uploadedFile->getClientOriginalExtension();
            $uploadedFile->move(dirname($tempPath), basename($tempPath));

            // Verificar que el archivo existe en la nueva ubicación
            if (!file_exists($tempPath)) {
                throw new \Exception("No se pudo guardar el archivo en una ubicación temporal: {$tempPath}");
            }
            // Iniciar transacción de base de datos
            DB::beginTransaction();

            try {
                // Extraer los datos del Excel directamente
                $rows = Excel::toArray([], $tempPath)[0] ?? [];

                // Eliminar filas de encabezado (primeras 3 filas)
                $rows = array_slice($rows, 3);

                Log::info("Excel procesado, filas encontradas: " . count($rows));

                // Agrupar préstamos y reposiciones
                $loanGroups = [];
                $reposicionData = [];
                $requestIds = [];
                $errors = [];
                $processedRows = 0;

                // Procesar cada fila del Excel
                $rowNumber = 4; // Empezamos en la fila 4 (índice 0 = fila 1)
                foreach ($rows as $row) {
                    $rowNumber++;

                    // Verificar si la fila está vacía
                    if (empty($row)) {
                        continue;
                    }

                    // Verificar si la fila tiene datos mínimos
                    // La validación original era demasiado estricta, relajémosla
                    $hasData = false;
                    foreach ($row as $cell) {
                        if (!empty($cell)) {
                            $hasData = true;
                            break;
                        }
                    }

                    if (!$hasData) {
                        continue;
                    }

                    // Asignar índices específicos para mayor control
                    $mappedRow = [
                        'fecha' => $row[0] ?? null,
                        'personnel_type' => $row[1] ?? null,
                        'no_factura' => $row[2] ?? null,
                        'cuenta' => $row[3] ?? null,
                        'valor' => $row[4] ?? null,
                        'proyecto' => $row[5] ?? null,
                        'responsable' => $row[6] ?? null,
                        'vehicle_plate' => $row[7] ?? null,
                        'cedula_responsable' => $row[8] ?? null,
                        'note' => $row[9] ?? "—",
                    ];

                    // Validaciones básicas pero menos estrictas
                    $rowErrors = [];
                    $skipRow = false;

                    // Si no hay valor, o si no hay personnel_type y no_factura, saltamos la fila
                    if (empty($mappedRow['valor']) || (!is_numeric($mappedRow['valor']) && !is_numeric(str_replace(',', '.', $mappedRow['valor'])))) {
                        // No es un error para registrar, simplemente saltamos
                        $skipRow = true;
                    }

                    // Si no tiene datos mínimos para identificar el préstamo
                    if (empty($mappedRow['no_factura']) && empty($mappedRow['proyecto'])) {
                        $skipRow = true;
                    }

                    if ($skipRow) {
                        continue;
                    }

                    // Normalizar valor (por si viene con coma decimal)
                    if (!is_numeric($mappedRow['valor'])) {
                        $mappedRow['valor'] = str_replace(',', '.', $mappedRow['valor']);
                        if (!is_numeric($mappedRow['valor'])) {
                            $rowErrors[] = "El valor no es numérico";
                        }
                    }

                    // Si personnel_type está vacío, intentamos determinar por otros campos
                    if (empty($mappedRow['personnel_type'])) {
                        if (!empty($mappedRow['responsable'])) {
                            $mappedRow['personnel_type'] = 'nomina';
                        } elseif (!empty($mappedRow['vehicle_plate'])) {
                            $mappedRow['personnel_type'] = 'proveedor';
                        } else {
                            // Por defecto
                            $mappedRow['personnel_type'] = 'nomina';
                        }
                    } else {
                        // Normalizar el tipo
                        $lowerType = strtolower(trim($mappedRow['personnel_type']));
                        if (in_array($lowerType, ['nomina', 'nómina', 'personal', 'empleado'])) {
                            $mappedRow['personnel_type'] = 'nomina';
                        } elseif (in_array($lowerType, ['proveedor', 'vehiculo', 'vehículo', 'auto'])) {
                            $mappedRow['personnel_type'] = 'proveedor';
                        } else {
                            // Si no coincide con ninguno conocido, lo dejamos como está
                        }
                    }

                    // Manejo de fechas más flexible
                    $date = null;

                    if (is_numeric($mappedRow['fecha']) && $mappedRow['fecha'] > 0) {
                        // Si es un número serial de Excel
                        try {
                            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($mappedRow['fecha']);
                        } catch (\Exception $e) {
                            Log::warning("Fecha Excel inválida en fila {$rowNumber}: " . $e->getMessage());
                            // Usar fecha actual como fallback
                            $date = now();
                        }
                    } else if (is_string($mappedRow['fecha']) && !empty($mappedRow['fecha'])) {
                        // Si es una fecha en formato string
                        try {
                            $date = Carbon::parse($mappedRow['fecha']);
                            // Verificar que sea una fecha válida
                            if (!$date->isValid()) {
                                throw new \Exception("Fecha inválida");
                            }
                        } catch (\Exception $e) {
                            Log::warning("Fecha string inválida en fila {$rowNumber}: " . $e->getMessage());
                            // Usar fecha actual como fallback
                            $date = now();
                        }
                    } else {
                        // Si no hay fecha, usar la actual
                        $date = now();
                    }

                    // Buscar la cuenta de forma más flexible
                    $account = null;
                    if (!empty($mappedRow['cuenta'])) {
                        $account = Account::where('name', 'like', '%' . $mappedRow['cuenta'] . '%')->first();
                    }

                    // Si no encontramos la cuenta, usamos una predeterminada o la creamos
                    if (!$account) {
                        $account = Account::first(); // Usar la primera cuenta como fallback
                        if (!$account) {
                            // Si no hay cuentas, crear una nueva
                            $account = Account::create([
                                'name' => $mappedRow['cuenta'] ?: 'Cuenta Importación',
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                        $rowErrors[] = "La cuenta '{$mappedRow['cuenta']}' no existe en el sistema, se usó una cuenta predeterminada.";
                    }

                    // Generar ID único para la solicitud
                    $prefix = 'P-';
                    $lastRequest = RequestModel::where('unique_id', 'like', 'P-%')
                        ->orderBy('id', 'desc')
                        ->first();
                    $nextId = $lastRequest ? ((int)str_replace($prefix, '', $lastRequest->unique_id) + 1) : 1;
                    $uniqueId = sprintf('%s%05d', $prefix, $nextId);

                    // Determinar ID del responsable o vehículo según el tipo
                    $responsibleId = null;
                    $vehicleId = null;

                    if (strtolower($mappedRow['personnel_type']) === 'nomina') {
                        $responsibleId = $mappedRow['responsable']; // Nombre del responsable
                    } else {
                        $vehicleId = $mappedRow['vehicle_plate']; // ID o placa del vehículo
                    }

                    if (!empty($responsibleId)) {
                        $cedulaResponsable = DB::connection('sistema_onix')->table('onix_personal')->where('nombre_completo', $responsibleId)->value('name');
                    } else {
                        $cedulaResponsable = null;
                    }

                    // Crear la solicitud (Request)
                    $requestData = [
                        'unique_id' => $uniqueId,
                        'type' => 'discount',
                        'personnel_type' => strtolower($mappedRow['personnel_type']),
                        'status' => 'in_reposition',
                        'request_date' => $date instanceof \DateTime ? $date->format('Y-m-d') : now()->format('Y-m-d'),
                        'invoice_number' => $mappedRow['no_factura'],
                        'account_id' => $account->name,
                        'amount' => floatval($mappedRow['valor']),
                        'project' => $mappedRow['proyecto'],
                        'responsible_id' => $responsibleId,
                        'cedula_responsable' => $cedulaResponsable,
                        'transport_id' => $vehicleId,
                        'note' => $mappedRow['note'] ?? "—",
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ];


                    // Crear el registro de solicitud
                    try {
                        $request = RequestModel::create($requestData);
                        $requestIds[] = $uniqueId;
                        $processedRows++;

                        // Agrupar solicitudes por criterios de préstamo (para crear loans)
                        $loanKey = md5($requestData['personnel_type'] .
                            $requestData['invoice_number'] .
                            $requestData['account_id'] .
                            $requestData['project'] .
                            $requestData['responsible_id'] .
                            $requestData['transport_id']);

                        if (!isset($loanGroups[$loanKey])) {
                            $loanGroups[$loanKey] = [
                                'requests' => [],
                                'total' => 0,
                                'metadata' => [
                                    'personnel_type' => $requestData['personnel_type'],
                                    'invoice_number' => $requestData['invoice_number'],
                                    'account_id' => $account->id,
                                    'account_name' => $account->name,
                                    'project' => $requestData['project'],
                                    'responsible_id' => $requestData['responsible_id'],
                                    'vehicle_id' => $requestData['transport_id'],
                                    'note' => $requestData['note'],
                                ]
                            ];
                        }

                        $loanGroups[$loanKey]['requests'][] = $uniqueId;
                        $loanGroups[$loanKey]['total'] += $requestData['amount'];

                        // Agrupar por proyecto para las reposiciones
                        $repoKey = md5($requestData['project']);
                        if (!isset($reposicionData[$repoKey])) {
                            $reposicionData[$repoKey] = [
                                'project' => $requestData['project'],
                                'requests' => [],
                                'total' => 0
                            ];
                        }

                        $reposicionData[$repoKey]['requests'][] = $uniqueId;
                        $reposicionData[$repoKey]['total'] += $requestData['amount'];
                    } catch (\Exception $e) {
                        Log::error("Error al crear la solicitud en fila {$rowNumber}: " . $e->getMessage());
                        $errors[] = "Error en fila {$rowNumber}: " . $e->getMessage();
                    }
                }

                // Registrar la cantidad de filas procesadas
                Log::info("Total de filas procesadas: {$processedRows} de " . count($rows));

                // Verificar si se procesaron datos
                if (empty($loanGroups)) {
                    throw new \Exception("No se encontraron datos válidos para importar en el archivo Excel. Verifica el formato o el contenido del archivo.");
                }

                // Subir el archivo a Google Cloud Storage
                $fileName = $uploadedFile->getClientOriginalName();
                $bucket = $this->storage->bucket($this->bucketName);
                if (!$bucket->exists()) {
                    throw new \Exception("El bucket '{$this->bucketName}' no existe o no es accesible");
                }

                $object = $bucket->upload(
                    fopen($tempPath, 'r'),
                    ['name' => $fileName]
                );

                $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

                // Crear préstamos agrupados
                foreach ($loanGroups as $groupKey => $groupData) {
                    $installments = count($groupData['requests']);

                    // Crear el préstamo
                    $loan = Loan::create([
                        'loan_date' => now(),
                        'type' => $groupData['metadata']['personnel_type'],
                        'account_id' => $groupData['metadata']['account_id'],
                        'account_name' => $groupData['metadata']['account_name'],
                        'amount' => $groupData['total'],
                        'project' => $groupData['metadata']['project'],
                        'file_path' => $fileName,
                        'note' => $groupData['metadata']['note'],
                        'installments' => $installments,
                        'responsible_id' => $groupData['metadata']['responsible_id'],
                        'vehicle_id' => $groupData['metadata']['vehicle_id'],
                        'status' => 'pending',
                    ]);
                }

                // Crear una única reposición para todos los préstamos
                // Recolectar todos los IDs de requests
                $allRequestIds = [];
                $totalAmount = 0;
                $uniqueProjects = []; // Para guardar los nombres de proyectos únicos

                foreach ($reposicionData as $repoKey => $repoData) {
                    $allRequestIds = array_merge($allRequestIds, $repoData['requests']);
                    $totalAmount += $repoData['total'];
                    if (!in_array($repoData['project'], $uniqueProjects)) {
                        $uniqueProjects[] = $repoData['project'];
                    }
                }

                // Determinar el nombre del proyecto para la reposición
                $projectName = "";
                if (count($uniqueProjects) === 1) {
                    // Si solo hay un proyecto, usamos ese nombre
                    $projectName = $uniqueProjects[0];
                } elseif (count($uniqueProjects) === 2) {
                    // Si hay dos proyectos, los combinamos
                    $projectName = $uniqueProjects[0] . " y " . $uniqueProjects[1];
                } else {
                    // Si hay tres o más, tomamos los primeros tres
                    $projectName = $uniqueProjects[0] . ", " . $uniqueProjects[1];
                    if (count($uniqueProjects) > 3) {
                        $projectName .= " (+" . (count($uniqueProjects) - 2) . " más)";
                    }
                }

                // Crear una única reposición para todos los requests
                if (!empty($allRequestIds)) {
                    $reposicion = Reposicion::create([
                        'fecha_reposicion' => now(),
                        'total_reposicion' => $totalAmount,
                        'status' => 'pending',
                        'project' => $projectName,
                        'detail' => $allRequestIds,
                        'attachment_url' => $fileUrl,
                        'attachment_name' => $fileName,
                        'note' => "Importación masiva de préstamos - " . now()->format('Y-m-d H:i:s'),
                    ]);

                    // Actualizar todas las solicitudes con el ID de esta única reposición
                    RequestModel::whereIn('unique_id', $allRequestIds)
                        ->update(['reposicion_id' => $reposicion->id]);

                    Log::info("Reposición única creada con ID: " . $reposicion->id . " para " . count($allRequestIds) . " solicitudes");
                }

                // Eliminar el archivo temporal
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }

                DB::commit();

                // Devolver respuesta exitosa o con errores parciales
                if (!empty($errors)) {
                    return response()->json([
                        'message' => 'Importación completada con advertencias',
                        'errors' => $errors,
                        'success' => true,
                        'requests_created' => count($requestIds)
                    ], 200);
                }

                return response()->json([
                    'message' => 'Importación completada exitosamente',
                    'success' => true,
                    'requests_created' => count($requestIds)
                ], 200);
            } catch (\Exception $innerException) {
                // Capturar excepciones específicas de la importación
                DB::rollBack();
                Log::error('Error en el proceso de importación:', [
                    'message' => $innerException->getMessage(),
                    'trace' => $innerException->getTraceAsString()
                ]);
                return response()->json([
                    'message' => 'Error al procesar la importación',
                    'error' => $innerException->getMessage()
                ], 500);
            }
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
