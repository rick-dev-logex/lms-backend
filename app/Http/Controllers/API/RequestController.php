<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\CajaChica;
use App\Models\Project;
use App\Models\Request;
use App\Models\User;
use App\Services\PersonnelService;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Excel;

class RequestController extends Controller
{
    private $personnelService;
    private $uniqueIdService;

    public function __construct(PersonnelService $personnelService)
    {
        $this->personnelService = $personnelService;
    }

    public function import(HttpRequest $request, Excel $excel)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'context' => 'required|in:discounts,expenses',
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

    public function index(HttpRequest $request)
    {
        try {
            $period = $request->input('period', 'last_3_months');

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
            }
            if ($period === 'last_3_months') {
                $query->where('created_at', '>=', now()->subMonths(3));
            }
            $query->with(['account:id,name']);
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['unique_id', 'created_at', 'updated_at', 'amount', 'project', 'status'];
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            $requests = $query->orderByDesc('id')->get();

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

            $requestRecord->responsible = $responsible;
            $requestRecord->transport = $transport;

            return response()->json($requestRecord);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la solicitud',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function store(HttpRequest $request)
    {
        try {
            $baseRules = [
                'type' => 'required|in:expense,discount,income',
                'personnel_type' => 'required|in:nomina,transportista',
                'request_date' => 'required|date',
                'invoice_number' => 'required|string',
                'account_id' => 'required|string',
                'amount' => 'required|numeric|min:0',
                'project' => 'required|string',
                'note' => 'required|string',
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'required|string';
            } else {
                $baseRules['vehicle_plate'] = 'required|string';
            }

            if ($request->has('vehicle_number')) {
                $baseRules['vehicle_number'] = 'nullable|string';
            }

            $validated = $request->validate($baseRules);

            // Verificar si ya existe un registro similar ANTES de generar un nuevo ID único
            $existingRequestQuery = Request::query();
            $existingRequestQuery->where('type', $validated['type']);
            $existingRequestQuery->where('personnel_type', $validated['personnel_type']);
            $existingRequestQuery->where('project', $validated['project']);
            $existingRequestQuery->where('invoice_number', $validated['invoice_number']);
            $existingRequestQuery->where('account_id', $validated['account_id']);
            $existingRequestQuery->where('amount', $validated['amount']);
            $existingRequestQuery->where('created_at', '>=', now()->subMinutes(5));
            $existingRequest = $existingRequestQuery->first();

            if ($existingRequest) {
                // Ya existe un registro similar creado recientemente
                return response()->json([
                    'message' => 'Ya existe un registro con la información provista.',
                    'data' => $existingRequest
                ], 200);
            }

            // // Generar el identificador único
            // $prefix = $validated['type'] === 'expense' ? 'G-' : ($validated['type'] === "income" ? 'I-' : ($validated['type'] === "loan" ? 'P-' : "D-"));

            // // Buscar el último unique_id para el tipo dado
            // $lastRecord = Request::where('type', $validated['type'])
            //     ->where('unique_id', 'like', $prefix . '%')
            //     ->orderByRaw('CAST(SUBSTRING(unique_id, 3) AS UNSIGNED) DESC')
            //     ->first();

            // $nextId = $lastRecord ? ((int)str_replace($prefix, '', $lastRecord->unique_id) + 1) : 1;
            // $uniqueId = $nextId <= 9999 ? sprintf('%s%05d', $prefix, $nextId) : sprintf('%s%d', $prefix, $nextId);

            // // Verificar si el unique_id ya existe y ajustar si es necesario
            // while (Request::where('unique_id', $uniqueId)->exists()) {
            //     $nextId++;
            //     $uniqueId = $nextId <= 9999 ? sprintf('%s%05d', $prefix, $nextId) : sprintf('%s%d', $prefix, $nextId);
            // }

            // ===== SOLUCIÓN RADICAL PARA GENERAR IDs ÚNICOS =====
            // En lugar de usar el servicio, generamos directamente un ID único 
            // que garantiza no colisionar con IDs existentes

            $prefix = '';
            switch (strtolower($validated['type'])) {
                case 'expense':
                    $prefix = 'G-';
                    break;
                case 'income':
                    $prefix = 'I-';
                    break;
                case 'discount':
                    $prefix = 'D-';
                    break;
                case 'loan':
                    $prefix = 'P-';
                    break;
                default:
                    $prefix = 'S-';
                    break;
            }

            // OPCIÓN 1: Saltar completamente a un rango alto
            // Obtener el máximo ID actual y saltar a un rango seguro
            $maxIdQuery = DB::table('requests')
                ->where('unique_id', 'like', $prefix . '%')
                ->selectRaw('MAX(CAST(SUBSTRING(unique_id, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) as max_id')
                ->first();

            $nextNumber = ($maxIdQuery && $maxIdQuery->max_id) ? ($maxIdQuery->max_id + 1) : 1;

            // Si el siguiente número es el problemático o está en un rango cercano, saltamos a un número seguro (300)
            if ($prefix === 'D-' && $nextNumber >= 220 && $nextNumber <= 230) {
                Log::warning("Saltando rango problemático cerca de D-00226. Siguiente ID normal sería: {$prefix}{$nextNumber}");
                $nextNumber = 300; // Saltar a un rango seguro
            }

            // Formatear el ID con ceros a la izquierda
            $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            // VERIFICACIÓN DE SEGURIDAD: si aún así el ID existe, usamos un ID completamente diferente
            if (DB::table('requests')->where('unique_id', $uniqueId)->exists()) {
                Log::error("ID duplicado detectado a pesar de las precauciones: {$uniqueId}. Cambiando a formato alternativo.");

                // OPCIÓN 2: Generar un ID totalmente diferente basado en timestamp y aleatorio
                $timestamp = now()->format('mdHis'); // formato: mes+día+hora+minuto+segundo
                $randomPart = mt_rand(100, 999);
                $uniqueId = $prefix . $timestamp . $randomPart;

                // Verificar nuevamente (aunque es extremadamente improbable que colisione)
                if (DB::table('requests')->where('unique_id', $uniqueId)->exists()) {
                    // OPCIÓN 3: Si todo lo anterior falla, usar un UUID truncado como último recurso
                    Log::error("ID basado en timestamp también duplicado. Usando UUID como último recurso.");
                    $uuid = substr(str_replace('-', '', \Illuminate\Support\Str::uuid()), 0, 10);
                    $uniqueId = $prefix . $uuid;
                }
            }


            // Guardar datos raw como llegan
            $requestData = [
                'type' => $validated['type'],
                'personnel_type' => $validated['personnel_type'],
                'project' => $validated['project'],
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'note' => $validated['note'],
                'unique_id' => $uniqueId,
                'status' => 'pending'
            ];

            if ($request->has('responsible_id')) {
                $requestData['responsible_id'] = $request->input('responsible_id');
                $cedula = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $requestData['responsible_id'])
                    ->value('name');
                $requestData['cedula_responsable'] = $cedula;
            }
            if ($request->has('vehicle_plate')) {
                $requestData['vehicle_plate'] = $request->input('vehicle_plate');
            }
            if ($request->has('vehicle_number')) {
                $requestData['vehicle_number'] = $request->input('vehicle_number');
            }

            $isUUID = is_string($requestData['project']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $requestData['project']);
            if ($isUUID) {
                $projectName = $requestData['project'];
                $project = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->where('id', $projectName)
                    ->pluck('name')
                    ->first();
                $requestData['project'] = $project;
            }

            // Si es que es numero, buscar el id de account y obtener el nombre
            if (is_numeric($requestData['account_id'])) {
                $accountName = Account::where('id', $requestData['account_id'])->pluck('name')->first();
                $requestData['account_id'] = $accountName;
            }

            $newRequest = Request::create($requestData);

            // Crear registro en CajaChica
            $this->createCajaChicaRecord($requestData, $newRequest->unique_id);

            return response()->json([
                'message' => 'Request created successfully',
                'data' => $newRequest
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'No se pudo crear el registro',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Crea un registro en la tabla CajaChica
     * 
     * @param array $requestData Datos de la solicitud
     * @param string $uniqueId ID único de la solicitud
     * @return void
     */
    private function createCajaChicaRecord(array $requestData, string $uniqueId): void
    {
        Log::debug('Intentando crear registro en CajaChica:', [
            'uniqueId' => $uniqueId,
            'request_date' => $requestData['request_date'],
            'type' => $requestData['type']
        ]);
        $numeroCuenta = Account::where('name', $requestData['account_id'])->pluck('account_number')->first();
        $nombreCuenta = strtoupper(\Illuminate\Support\Str::ascii($requestData['account_id'])); // sin tildes
        $proyecto = strtoupper($requestData['project']);

        // Formatear centro_costo: ENE 2025, ABR 2025, etc.
        $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
        $fecha = \Carbon\Carbon::parse($requestData['request_date']);
        $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;

        // mes_servicio: 1/1/2025, 1/2/2025, etc.
        $mesServicio = $fecha->format('j/n/Y');

        CajaChica::create([
            'FECHA' => $requestData['request_date'],
            'CODIGO' => "CAJA CHICA " . $uniqueId,
            'DESCRIPCION' => $requestData['note'],
            'SALDO' => $requestData['amount'],
            'CENTRO_COSTO' => $centroCosto,
            'CUENTA' => $numeroCuenta,
            'NOMBRE_DE_CUENTA' => $nombreCuenta,
            'PROVEEDOR' => $requestData['type'] === "expense" ? 'CAJA CHICA' : ($requestData['type'] === "discount" ? "DESCUENTOS" : "INGRESO"),
            'EMPRESA' => 'SERSUPPORT',
            'PROYECTO' => $proyecto,
            'I_E' => $requestData['type'] === "income" ? 'INGRESO' : 'EGRESO',
            'MES_SERVICIO' => $mesServicio,
            'TIPO' => $requestData['type'] === "expense" ? "GASTO" : ($requestData['type'] === "discount" ? "DESCUENTO" : "INGRESO"),
            'ESTADO' => $requestData['status'],
        ]);
        Log::debug('Registro en CajaChica creado con éxito');
    }

    public function update(HttpRequest $request, $id)
    {
        $requestModel = Request::findOrFail($id);

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
                // Obtener la cédula del responsable desde la base de datos externa
                $cedula = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $request->input('responsible_id'))
                    ->value('name');

                if ($cedula) {
                    $request->merge(['cedula_responsable' => $cedula]);
                }
            } else if ($request->has('personnel_type') && $request->input('personnel_type') === 'transportista') {
                $baseRules['vehicle_plate'] = 'sometimes|exists:sistema_onix.onix_vehiculos,name';
            }

            if ($request->has('project')) {
                $projectName = $request->input('project');
                $isUUID = is_string($projectName) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $projectName);

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

            // Actualizar registro principal
            $requestModel->update($validated);

            // Actualizar registro correspondiente en CajaChica
            $this->updateCajaChicaRecord($requestModel);

            return response()->json($requestModel);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la solicitud',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Actualiza el registro correspondiente en CajaChica
     * 
     * @param Request $requestModel El modelo de solicitud actualizado
     * @return void
     */
    private function updateCajaChicaRecord(Request $requestModel): void
    {
        try {
            $cajaChica = CajaChica::where('CODIGO', 'CAJA CHICA' . $requestModel->unique_id)->first();

            if ($cajaChica) {
                $numeroCuenta = Account::where('name', $requestModel->account_id)->pluck('account_number')->first();
                $nombreCuenta = strtoupper(\Illuminate\Support\Str::ascii($requestModel->account_id));
                $proyecto = strtoupper($requestModel->project);

                // Formatear centro_costo: ENE 2025, ABR 2025, etc.
                $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
                $fecha = \Carbon\Carbon::parse($requestModel->request_date);
                $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;

                // mes_servicio: 1/1/2025, 1/2/2025, etc.
                $mesServicio = $fecha->format('j/n/Y');

                $cajaChica->update([
                    'FECHA' => $requestModel->request_date,
                    'DESCRIPCION' => $requestModel->note,
                    'SALDO' => $requestModel->amount,
                    'CENTRO_COSTO' => $centroCosto,
                    'CUENTA' => $numeroCuenta,
                    'NOMBRE_DE_CUENTA' => $nombreCuenta,
                    'PROYECTO' => $proyecto,
                    'MES_SERVICIO' => $mesServicio,
                    'ESTADO' => $requestModel->status,
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error al actualizar registro en CajaChica:', [
                'request_id' => $requestModel->id,
                'error' => $e->getMessage()
            ]);
            // No lanzamos excepción para no interrumpir la actualización principal
        }
    }

    public function destroy(Request $requestRecord)
    {
        try {
            DB::beginTransaction();

            // Eliminar registro relacionado en CajaChica
            CajaChica::where('codigo', 'CAJA CHICA' . $requestRecord->unique_id)->delete();

            // Eliminar solicitud
            $requestRecord->delete();

            DB::commit();
            return response()->json(['message' => 'Registro eliminado exitosamente']);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar solicitud:', [
                'request_id' => $requestRecord->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Error al eliminar el registro',
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

                    // Crear registro en CajaChica
                    $this->createCajaChicaRecord($mappedData, $newRequest->unique_id);

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
