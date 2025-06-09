<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\Project;
use App\Models\Reposicion;
use App\Models\Request;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UniqueIdService;
use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel;

class RequestController extends Controller
{
    private $uniqueIdService;
    protected $authService;

    public function __construct(UniqueIdService $uniqueIdService, AuthService $authService)
    {
        $this->uniqueIdService = $uniqueIdService;
        $this->authService = $authService;
    }

    public function import(HttpRequest $request, Excel $excel)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'context' => 'required|in:discounts,expenses,income',
        ]);

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $context = $request->input('context');

            $jwtToken = $request->cookie('jwt-token');
            if (!$jwtToken) {
                throw new Exception("No se encontró el token de autenticación en la cookie.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;

            if (!$userId) {
                throw new Exception("No se encontró el ID de usuario en el token JWT.");
            }

            $import = new RequestsImport($context, $userId, $this->uniqueIdService);
            $excel->import($import, $file);

            if (!empty($import->errors)) {
                throw new Exception(json_encode($import->errors));
            }

            // Aquí NO se inserta nada más.

            DB::commit();
            return response()->json(['message' => 'Importación exitosa'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en la importación: ' . $e->getMessage());
            $errors = json_decode($e->getMessage(), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errors)) {
                return response()->json(['errors' => $errors], 400);
            }
            return response()->json(['errors' => [$e->getMessage()]], 500);
        }
    }

    /**
     * Maneja tanto solicitudes individuales como masivas
     */
    public function store(HttpRequest $request)
    {
        try {
            $user = $this->authService->getUser($request);

            // Detectar si es una solicitud masiva
            $isBatchRequest = $request->has('batch_data') || $request->has('requests');

            if ($isBatchRequest) {
                return $this->storeBatch($request, $user);
            } else {
                return $this->storeSingle($request, $user);
            }
        } catch (\Exception $e) {
            Log::error('Error in store method', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user' => $user->email ?? 'unknown'
            ]);

            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Procesa una solicitud individual
     */
    private function storeSingle(HttpRequest $request, $user)
    {
        $baseRules = [
            'type' => 'required|in:expense,discount,income',
            'personnel_type' => 'required|in:nomina,transportista',
            'request_date' => 'required|date',
            'invoice_number' => 'required|string|max:100',
            'account_id' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'project' => 'required|string|max:50',
            'note' => 'required|string|max:1000',
        ];

        if ($request->input('personnel_type') === 'nomina') {
            $baseRules['responsible_id'] = 'required|string|max:255';
        } else {
            $baseRules['vehicle_plate'] = 'required|string|max:20';
            $baseRules['vehicle_number'] = 'nullable|string|max:50';
        }

        $validated = $request->validate($baseRules);

        DB::beginTransaction();

        try {
            // Validación de duplicados más flexible para solicitudes individuales
            $duplicateQuery = Request::where([
                'type' => $validated['type'],
                'project' => $validated['project'],
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount']
            ])->where('created_at', '>=', now()->subMinutes(2)); // Ventana reducida a 2 minutos

            if ($validated['personnel_type'] === 'nomina') {
                $duplicateQuery->where('responsible_id', $validated['responsible_id']);
            } else {
                $duplicateQuery->where('vehicle_plate', $validated['vehicle_plate']);
            }

            if ($duplicateQuery->exists()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Ya existe una solicitud idéntica creada recientemente.',
                    'error' => 'DUPLICATE_REQUEST'
                ], 422);
            }

            $newRequest = $this->createRequestRecord($validated, $user);

            DB::commit();

            return response()->json([
                'message' => 'Solicitud creada exitosamente',
                'data' => $newRequest
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Procesa solicitudes masivas con control de concurrencia
     */
    private function storeBatch(HttpRequest $request, $user)
    {
        // Validar que sea una solicitud masiva válida
        $batchData = $request->input('batch_data') ?? $request->input('requests');

        if (!$batchData || !is_array($batchData)) {
            return response()->json([
                'message' => 'Datos de lote inválidos',
                'error' => 'INVALID_BATCH_DATA'
            ], 422);
        }

        $maxBatchSize = 2500; // Limitar el tamaño del lote
        if (count($batchData) > $maxBatchSize) {
            return response()->json([
                'message' => "El lote excede el máximo de {$maxBatchSize} registros",
                'error' => 'BATCH_TOO_LARGE'
            ], 422);
        }

        $results = [
            'success' => [],
            'errors' => [],
            'total' => count($batchData),
            'processed' => 0
        ];

        // Procesar en lotes más pequeños para evitar timeouts
        $chunkSize = 500;
        $chunks = array_chunk($batchData, $chunkSize);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                DB::beginTransaction();

                $chunkResults = $this->processBatchChunk($chunk, $user, $chunkIndex * $chunkSize);

                $results['success'] = array_merge($results['success'], $chunkResults['success']);
                $results['errors'] = array_merge($results['errors'], $chunkResults['errors']);
                $results['processed'] += count($chunk);

                DB::commit();

                // Pequeña pausa entre chunks para evitar sobrecarga
                usleep(100000); // 0.1 segundos

            } catch (\Exception $e) {
                DB::rollBack();

                Log::error("Error procesando chunk {$chunkIndex}", [
                    'error' => $e->getMessage(),
                    'chunk_size' => count($chunk)
                ]);

                // Agregar errores para todos los items del chunk fallido
                foreach ($chunk as $itemIndex => $item) {
                    $results['errors'][] = [
                        'index' => $chunkIndex * $chunkSize + $itemIndex,
                        'data' => $item,
                        'error' => 'Error en el procesamiento del lote: ' . $e->getMessage()
                    ];
                }
            }
        }

        $successCount = count($results['success']);
        $errorCount = count($results['errors']);

        return response()->json([
            'message' => "Procesamiento completado: {$successCount} exitosos, {$errorCount} errores",
            'results' => $results,
            'summary' => [
                'total' => $results['total'],
                'success' => $successCount,
                'errors' => $errorCount,
                'success_rate' => $results['total'] > 0 ? round(($successCount / $results['total']) * 100, 2) : 0
            ]
        ], $errorCount > 0 ? 207 : 201); // 207 = Multi-Status
    }

    /**
     * Procesa un chunk de datos del lote
     */
    private function processBatchChunk(array $chunk, $user, int $baseIndex)
    {
        $results = ['success' => [], 'errors' => []];

        foreach ($chunk as $index => $requestData) {
            try {
                // Validar datos individuales
                $validated = $this->validateBatchItem($requestData);

                // Crear registro sin validación de duplicados estricta para lotes
                $newRequest = $this->createRequestRecord($validated, $user, true);

                $results['success'][] = [
                    'index' => $baseIndex + $index,
                    'unique_id' => $newRequest->unique_id,
                    'data' => $newRequest
                ];
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'index' => $baseIndex + $index,
                    'data' => $requestData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Valida un item individual del lote
     */
    private function validateBatchItem(array $data)
    {
        $rules = [
            'type' => 'required|in:expense,discount,income',
            'personnel_type' => 'required|in:nomina,transportista',
            'request_date' => 'required|date',
            'invoice_number' => 'required|string|max:100',
            'account_id' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'project' => 'required|string|max:50',
            'note' => 'required|string|max:1000',
        ];

        if (($data['personnel_type'] ?? null) === 'nomina') {
            $rules['responsible_id'] = 'required|string|max:255';
        } else {
            $rules['vehicle_plate'] = 'required|string|max:20';
            $rules['vehicle_number'] = 'nullable|string|max:50';
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Crea un registro de solicitud
     */
    private function createRequestRecord(array $validated, $user, bool $isBatch = false)
    {
        // Para lotes, verificar duplicados de manera más eficiente
        if ($isBatch) {
            $this->checkForBatchDuplicates($validated);
        }

        // Generar unique_id con retry limitado
        $maxRetries = $isBatch ? 3 : 5;
        $uniqueId = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            $tempId = $this->uniqueIdService->generateUniqueRequestId($validated['type']);

            $exists = $isBatch
                ? Request::where('unique_id', $tempId)->exists()
                : Request::lockForUpdate()->where('unique_id', $tempId)->exists();

            if (!$exists) {
                $uniqueId = $tempId;
                break;
            }

            if ($i < $maxRetries - 1) {
                usleep($isBatch ? 1000 : 10000);
            }
        }

        if (!$uniqueId) {
            throw new \Exception('No se pudo generar un ID único después de varios intentos');
        }

        // Preparar datos
        $requestData = [
            'type' => $validated['type'],
            'personnel_type' => $validated['personnel_type'],
            'project' => strtoupper(trim($validated['project'])),
            'request_date' => $validated['request_date'],
            'invoice_number' => trim($validated['invoice_number']),
            'account_id' => trim($validated['account_id']),
            'amount' => (float) $validated['amount'],
            'note' => trim($validated['note']),
            'unique_id' => $uniqueId,
            'status' => 'pending',
            'created_by' => $user->name,
            'responsible_id' => $validated['responsible_id'] ?? null,
            'vehicle_plate' => $validated['vehicle_plate'] ?? null,
            'vehicle_number' => $validated['vehicle_number'] ?? null,
        ];

        // Manejar responsible_id y cédula
        if (isset($validated['responsible_id'])) {
            $cedula = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->where('nombre_completo', $requestData['responsible_id'])
                ->value('name');
            $requestData['cedula_responsable'] = $cedula;
        }

        return Request::create($requestData);
    }

    /**
     * Verificación optimizada de duplicados para lotes
     */
    private function checkForBatchDuplicates(array $validated)
    {
        // Solo verificar duplicados exactos recientes para lotes (ventana más pequeña)
        $recentDuplicate = Request::where([
            'type' => $validated['type'],
            'project' => $validated['project'],
            'request_date' => $validated['request_date'],
            'invoice_number' => $validated['invoice_number'],
            'account_id' => $validated['account_id'],
            'amount' => $validated['amount']
        ])
            ->where('created_at', '>=', now()->subSeconds(30)) // Solo 30 segundos para lotes
            ->exists();

        if ($recentDuplicate) {
            throw new \Exception(
                "Duplicado detectado: {$validated['invoice_number']} - {$validated['account_id']}"
            );
        }
    }

    // Mantener el resto de métodos existentes...
    public function index(HttpRequest $request)
    {
        try {
            $period = $request->input('period', 'last_month');

            // Extract user and assigned projects from JWT
            $jwtToken = $request->cookie('jwt-token');
            if (!$jwtToken) {
                throw new Exception("No se encontró el token de autenticación en la cookie.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;
            if (!$userId) {
                throw new Exception("No se encontró el ID de usuario en el token JWT.");
            }

            // Obtener usuario y sus proyectos asignados
            $user = User::find($userId);
            if (!$user) {
                throw new Exception("Usuario no encontrado.");
            }

            // Procesar proyectos asignados correctamente
            $assignedProjectIds = [];
            if ($user && isset($user->assignedProjects)) {
                if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                    $projectsValue = $user->assignedProjects->projects;
                    if (is_string($projectsValue)) {
                        $assignedProjectIds = json_decode($projectsValue, true) ?: [];
                    } else if (is_array($projectsValue)) {
                        $assignedProjectIds = $projectsValue;
                    }
                } else if (is_array($user->assignedProjects)) {
                    $assignedProjectIds = $user->assignedProjects;
                }
            }
            if (!empty($assignedProjectIds)) {
                $assignedProjectIds = array_map('strval', $assignedProjectIds);
            }

            // Build the query
            $query = Request::query();

            // Fetch project names for the user's assigned UUIDs
            $projectNames = [];
            if (!empty($assignedProjectIds)) {
                $projectNames = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->whereIn('id', $assignedProjectIds)
                    ->pluck('name')
                    ->toArray();
                $query->whereIn('project', $projectNames);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            } else {
                $query->where('status', "pending");
            }

            $query->whereNull('reposicion_id');

            if ($period === 'last_3_months') {
                $query->where('created_at', '>=', Carbon::now()->subMonths(3)->startOfMonth());
            }
            if ($period === 'last_month') {
                $query->where('created_at', '>=', Carbon::now()->subMonth()->startOfMonth());
            }
            if ($period === 'last_week') {
                $query->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek());
            }
            $query->with(['account:id,name']);
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['unique_id', 'created_at', 'updated_at', 'amount', 'project', 'status'];
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            $requests = $query->orderByDesc('created_at')->get();

            if ($request->type === 'income') {
                $requests->where('type', "income");
            } elseif ($request->type === "expense") {
                $requests->where('type', "expense");
            } elseif ($request->type === "discount") {
                $requests->where('type', "discount");
            }

            // Transform data (keep project_name for frontend)
            $projects = !empty($assignedProjectIds) ? DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->select('id', 'name')
                ->get()
                ->mapWithKeys(function ($project) {
                    return [$project->id => $project->name];
                })->all() : [];
            $data = $requests->map(function ($request) use ($projects) {
                $requestData = $request->toArray();
                // Since project is a name, use it directly; map UUID to name if needed
                $requestData['project_name'] = $request->project; // Already a name
                return $requestData;
            })->all();

            return response()->json($data);
        } catch (Exception $e) {
            Log::error('Error in RequestController@index:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $requestRecord = Request::with(['account:id,name'])->findOrFail($id);
            $responsible = null;
            $transport = null;

            if ($requestRecord->responsible_id) {
                $responsible = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('id', $requestRecord->responsible_id)
                    ->select('id', 'nombre_completo')
                    ->first();
            }

            if ($requestRecord->transport_id) {
                $transport = DB::connection('sistema_onix')
                    ->table('onix_vehiculos')
                    ->where('id', $requestRecord->transport_id)
                    ->select('id', 'name')
                    ->first();
            }

            $requestRecord->responsible_id = $responsible;
            $requestRecord->vehicle_plate = $transport;

            return response()->json($requestRecord);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la solicitud',
                'error' => $e->getMessage()
            ], 404);
        }
    }


    public function update(HttpRequest $request, $id)
    {
        try {
            $user = $this->authService->getUser($request);
            // Obtener el modelo completo de la solicitud
            $requestModel = is_numeric($id) ? Request::where('id', $id)->first() : Request::where('unique_id', $id)->first();

            // Validación en caso de que no se encuentre
            if (!$requestModel) {
                return response()->json(['message' => 'La solicitud seleccionada no fue encontrada o fue eliminada del sistema.'], 404);
            }

            $reposicionId = $requestModel->reposicion_id; // Cambio aquí
        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error al obtener el ID de la solicitud",
                "error" => $e->getMessage()
            ]);
        }

        try {
            $baseRules = [
                'status' => 'sometimes|in:pending,paid,rejected,review,in_reposition',
                'request_date' => 'sometimes|date',
                'account_id' => 'sometimes|string',
                'invoice_number' => 'sometimes|string',
                'amount' => 'sometimes|numeric',
                'project' => 'sometimes|string',
                'vehicle_number' => 'sometimes|string',
                'note' => 'sometimes|string',
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'sometimes|exists:sistema_onix.onix_personal,nombre_completo';

                $cedula = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $request->input('responsible_id'))
                    ->value('name');

                if ($cedula) {
                    $request->merge(['cedula_responsable' => $cedula]);
                }
            } elseif ($request->input('personnel_type') === 'transportista') {
                $baseRules['vehicle_plate'] = 'sometimes|exists:sistema_onix.onix_vehiculos,name';
            }

            if ($request->has('project')) {
                $projectName = $request->input('project');
                $isUUID = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $projectName);

                if ($isUUID) {
                    $project = DB::connection('sistema_onix')
                        ->table('onix_proyectos')
                        ->where('id', $projectName)
                        ->value('name');

                    if ($project) {
                        $request->merge(['project' => $project]);
                    }
                }
            }

            $validated = $request->validate($baseRules);
            $validated['updated_by'] = $user->name;

            // Primero actualizamos la solicitud
            $requestModel->update($validated);

            // Luego recalculamos el total de la reposición asociada si existe
            if ($reposicionId) {
                $this->updateReposicion($reposicionId);
            }

            // Refrescar para obtener los nuevos datos
            $requestModel->refresh();

            return response()->json($requestModel);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la solicitud',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Actualiza el registro correspondiente en Reposiciones
     * 
     * @param $id El id de la reposición a la que corresponde el registro para actualizar el valor total
     * @return void
     */
    private function updateReposicion($id)
    {
        if (!$id) return;

        $reposicion = Reposicion::find($id);
        if (!$reposicion) return;

        // Usar la nueva relación para calcular el total
        $sum = $reposicion->calculateTotal();

        $reposicion->update([
            'total_reposicion' => $sum,
            'updated_at' => Carbon::now(),
        ]);

        Log::info("Reposición #{$id} actualizada con total: {$sum}");
    }

    public function destroy(HttpRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = $this->authService->getUser($request);

            // Buscar la solicitud
            $requestRecord = Request::where('unique_id', $id)->firstOrFail();

            // Si ya tiene una reposición, impedir eliminación
            if ($requestRecord->reposicion_id) {
                return response()->json([
                    'message' => 'No se puede eliminar la solicitud porque ya está asociada a una reposición.'
                ], 403);
            }

            // Marcar la solicitud como deleted y hacer soft delete
            $requestRecord->update([
                'status' => 'deleted',
                'updated_by' => $user->name,
            ]);
            $requestRecord->delete();

            DB::commit();
            return response()->json(['message' => 'Registro eliminado exitosamente']);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar solicitud:', [
                'unique_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Error al eliminar el registro',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Eliminar múltiples solicitudes por lotes
     *
     * @param  HttpRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(HttpRequest $request)
    {
        try {
            DB::beginTransaction();

            $requestIds = $request->input('request_ids', []);

            if (empty($requestIds)) {
                return response()->json([
                    'message' => 'No se proporcionaron IDs para eliminar',
                    'errors' => ['request_ids' => ['El campo request_ids es requerido y debe ser un array.']]
                ], 422);
            }

            // Obtener todos los registros a eliminar
            $requests = Request::whereIn('unique_id', $requestIds)->get();

            // Verificar que todos los IDs proporcionados existan
            if ($requests->count() !== count($requestIds)) {
                $foundIds = $requests->pluck('unique_id')->toArray();
                $missingIds = array_diff($requestIds, $foundIds);

                return response()->json([
                    'message' => 'Algunas solicitudes no fueron encontradas',
                    'errors' => ['missing_ids' => $missingIds]
                ], 422);
            }

            // Verificar si alguna solicitud tiene reposición asociada
            $conReposicion = $requests->filter(fn($r) => !is_null($r->reposicion_id));

            if ($conReposicion->isNotEmpty()) {
                return response()->json([
                    'message' => 'Algunas solicitudes no pueden eliminarse porque están asociadas a una reposición.',
                    'errors' => [
                        'con_reposicion' => $conReposicion->pluck('unique_id')->values()
                    ]
                ], 403);
            }

            // Actualizar el estado y eliminar (soft delete)
            foreach ($requests as $req) {
                $req->update([
                    'status' => 'deleted',
                    'updated_by' => $this->authService->getUser($request)->name,
                ]);
                $req->delete();
            }

            DB::commit();

            return response()->json([
                'message' => count($requestIds) . ' registros eliminados exitosamente',
                'deleted_count' => count($requestIds),
                'deleted_ids' => $requestIds
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error al eliminar solicitudes por lotes:', [
                'request_ids' => $requestIds ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al eliminar los registros',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadDiscounts(HttpRequest $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls',
                'data' => 'required|json'
            ]);

            $discounts = json_decode($request->data, true);
            $processedCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($discounts as $index => $discount) {
                try {
                    $personnelType = $this->normalizePersonnelType($discount['Tipo']);
                    if (!in_array($personnelType, ['nomina', 'transportista'])) {
                        throw new Exception("Tipo de personal inválido: {$discount['Tipo']}. Debe ser 'Nómina/nomina' o 'Transportista/transportista'");
                    }

                    $projectName = trim($discount['Proyecto']);
                    $project = Project::where('name', $projectName)->first();
                    if (!$project) {
                        throw new Exception("Proyecto no encontrado: {$projectName}");
                    }

                    if ($personnelType === 'nomina') {
                        $responsibleId = $this->getResponsibleId($discount['Responsable']);
                    } else {
                        if (!isset($discount['Placa']) || empty($discount['Placa'])) {
                            throw new Exception("La placa del vehículo es requerida para transportistas");
                        }
                        $transportId = $this->getTransportId($discount['Placa']);
                    }

                    $mappedData = [
                        'type' => 'discount',
                        'personnel_type' => $personnelType,
                        'status' => 'pending',
                        'request_date' => date('Y-m-d', strtotime($discount['Fecha'])),
                        'invoice_number' => $discount['No. Factura'],
                        'account_id' => $this->getAccountId($discount['Cuenta']),
                        'amount' => floatval($discount['Valor']),
                        'project' => $project,
                        'responsible_id' => $personnelType === 'nomina' ? $responsibleId : null,
                        'vehicle_plate' => $personnelType === 'transportista' ? $transportId : null,
                        'note' => $discount['Observación'],
                    ];

                    $validator = Validator::make($mappedData, [
                        'type' => 'required|in:expense,discount',
                        'personnel_type' => 'required|in:nomina,transportista',
                        'request_date' => 'required|date',
                        'invoice_number' => 'required|string',
                        'account_id' => 'required|exists:accounts,id',
                        'amount' => 'required|numeric|min:0',
                        'project' => 'required|string',
                        'note' => 'required|string'
                    ]);

                    if ($validator->fails()) {
                        $errorMessages = $validator->errors()->all();
                        throw new Exception("Error en la fila " . ($index + 2) . ": " . implode(", ", $errorMessages));
                    }

                    // Usar servicio para generar ID único
                    $mappedData['unique_id'] = $this->uniqueIdService->generateUniqueRequestId('discount');

                    $newRequest = Request::create($mappedData);

                    $processedCount++;
                } catch (Exception $e) {
                    $errors[] = [
                        'row' => $index + 2,
                        'data' => $discount,
                        'error' => $e->getMessage()
                    ];
                }
            }

            if (empty($errors)) {
                DB::commit();
                return response()->json([
                    'message' => 'Descuentos procesados exitosamente',
                    'processed' => $processedCount
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'message' => 'Se encontraron errores al procesar los descuentos',
                    'errors' => $errors
                ], 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al procesar los descuentos',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    private function normalizePersonnelType($type)
    {
        $normalized = strtolower(trim($type));
        $normalized = str_replace('ó', 'o', $normalized);

        $mappings = [
            'nomina' => 'nomina',
            'nómina' => 'nomina',
            'transportista' => 'transportista',
            'transporte' => 'transportista'
        ];

        return $mappings[$normalized] ?? $normalized;
    }

    private function getAccountId($accountName)
    {
        $account = Account::where('name', $accountName)->first();
        if (!$account) {
            throw new Exception("Cuenta no encontrada: {$accountName}");
        }
        return $account->name;
    }

    private function getResponsibleId($responsibleName)
    {
        $responsible = DB::connection('sistema_onix')
            ->table('onix_personal')
            ->where('nombre_completo', $responsibleName)
            ->first();

        if (!$responsible) {
            throw new Exception("Responsable no encontrado: {$responsibleName}");
        }
        return $responsible->nombre_completo;
    }

    private function getTransportId($plate)
    {
        $transport = DB::connection('sistema_onix')
            ->table('onix_vehiculos')
            ->where('name', $plate)
            ->first();

        if (!$transport) {
            throw new Exception("Vehículo no encontrado con placa: {$plate}");
        }
        return $transport->name;
    }

    public function updateRequestsData(Request $request)
    {
        try {
            // Obtener los proyectos externos: arreglo de uuid => name
            $proyectos = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->pluck('name', 'id')
                ->toArray();

            // Obtener el personal externo: arreglo de uuid => nombre_completo
            $personal = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->pluck('nombre_completo', 'id')
                ->toArray();

            $cuentas = DB::connection('sistema_onix')
                ->table('lms_backend')
                ->pluck('name', 'id')
                ->toArray();

            // Contadores para saber cuántos registros se actualizaron
            $updatedProjects = 0;
            $updatedResponsibles = 0;
            $updatedAccounts = 0;

            // Actualizar columna project en la DB local

            // Obtenemos las requests que tengan un valor de project (uuid) que exista en $proyectos
            $requestsProject = DB::table('requests')
                ->whereIn('project', array_keys($proyectos))
                ->get(['id', 'project']);

            foreach ($requestsProject as $req) {
                // Si se encontró el proyecto en el arreglo, actualizamos
                if (isset($proyectos[$req->project])) {
                    DB::table('requests')
                        ->where('id', $req->id)
                        ->update(['project' => $proyectos[$req->project]]);
                    $updatedProjects++;
                }
            }

            // Actualizar columna responsible_id en la DB local
            // Obtenemos las requests que tengan un valor de responsible_id (uuid) que exista en $personal
            $requestsResponsible = DB::table('requests')
                ->whereIn('responsible_id', array_keys($personal))
                ->get(['id', 'responsible_id']);

            foreach ($requestsResponsible as $req) {
                if (isset($personal[$req->responsible_id])) {
                    DB::table('requests')
                        ->where('id', $req->id)
                        ->update(['responsible_id' => $personal[$req->responsible_id]]);
                    $updatedResponsibles++;
                }
            }

            $requestsAccount = DB::table('requests')
                ->whereIn('account_id', array_keys($cuentas))
                ->get(['id', 'account_id']);

            foreach ($requestsAccount as $req) {
                if (isset($cuentas[$req->account_id])) {
                    DB::table('requests')
                        ->where('id', $req->id)
                        ->update(['account_id' => $cuentas[$req->account_id]]);
                    $updatedAccounts++;
                }
            }

            // Retornamos una respuesta con el número de filas actualizadas
            return response()->json([
                'message' => 'Datos actualizados correctamente',
                'rows_project_updated' => $updatedProjects,
                'rows_responsible_updated' => $updatedResponsibles,
                'rows_account_updated' => $updatedAccounts
            ]);
        } catch (Exception $e) {
            Log::error('Error en updateRequestsData: ' . $e->getMessage());
            return response()->json(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }
}
