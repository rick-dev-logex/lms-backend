<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\CajaChica;
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
use Maatwebsite\Excel\Excel;

class RequestController extends Controller
{
    private $uniqueIdService;
    protected $authService;

    /**
     * Constructor del controlador
     * 
     * @param UniqueIdService $uniqueIdService Servicio para generar IDs únicos
     */
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
            }
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

            $requests = $query->orderByDesc('id')->get();

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
            $user = $this->authService->getUser($request);
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

            // Convertir project a nombre antes de la validación de duplicados
            $projectValue = trim($validated['project']);
            if (strlen($projectValue) > 7) {
                // Posible UUID
                $projectName = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->where('id', $projectValue)
                    ->value('name');

                if (!$projectName) {
                    throw new Exception('El proyecto con ID ' . $projectValue . ' no ha sido encontrado.');
                }
                $validated['project'] = $projectName;
            } else {
                // Posible nombre
                $exists = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->where('name', $projectValue)
                    ->exists();

                if (!$exists) {
                    throw new Exception('Nombre de proyecto ' . $projectValue . ' no encontrado.');
                }
                $validated['project'] = $projectValue;
            }

            // Validación de duplicados (usando el nombre del proyecto)
            $duplicateCheck = DB::table('requests')
                ->where('type', $validated['type'])
                ->where('personnel_type', $validated['personnel_type'])
                ->where('project', $validated['project'])
                ->where('request_date', $validated['request_date'])
                ->where('invoice_number', $validated['invoice_number'])
                ->where('account_id', $validated['account_id'])
                ->where('amount', $validated['amount'])
                ->where('created_at', '>=', now()->subMinutes(5));

            if ($duplicateCheck->exists()) {
                return response()->json([
                    'message' => 'Ya existe un registro con la información provista.',
                    'data' => $duplicateCheck->first()
                ], 200);
            }

            // Generar un ID único usando el servicio
            $uniqueId = $this->uniqueIdService->generateUniqueRequestId($validated['type']);

            // Preparar datos para guardar
            $requestData = [
                'type' => $validated['type'],
                'personnel_type' => $validated['personnel_type'],
                'project' => $validated['project'], // Ya es el nombre
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'note' => $validated['note'],
                'unique_id' => $uniqueId,
                'status' => 'pending',
                'created_by' => $user->name,
            ];

            // Manejar responsible_id y cédula
            if ($request->has('responsible_id')) {
                $requestData['responsible_id'] = $request->input('responsible_id');
                $cedula = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $requestData['responsible_id'])
                    ->value('name');
                $requestData['cedula_responsable'] = $cedula;
            }

            // Manejar campos de transportista
            if ($request->has('vehicle_plate')) {
                $requestData['vehicle_plate'] = $request->input('vehicle_plate');
            }
            if ($request->has('vehicle_number')) {
                $requestData['vehicle_number'] = $request->input('vehicle_number');
            }

            // Convertir account_id a nombre si es numérico
            if (is_numeric($requestData['account_id'])) {
                $accountName = Account::where('id', $requestData['account_id'])->pluck('name')->first();
                if (!$accountName) {
                    throw new Exception('Cuenta con ID ' . $requestData['account_id'] . ' no encontrada.');
                }
                $requestData['account_id'] = $accountName;
            }

            // Crear el registro
            $newRequest = Request::create($requestData);

            // Crear registro en CajaChica si es que NO es ingreso
            if ($requestData['type'] !== "income") {
                $this->createCajaChicaRecord($requestData, $newRequest->unique_id);
            }

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
        try {
            $numeroCuenta = Account::where('name', $requestData['account_id'])->pluck('account_number')->first();
            $nombreCuenta = strtoupper(\Illuminate\Support\Str::ascii($requestData['account_id'])); // sin tildes
            $proyecto = strtoupper($requestData['project']);

            // Formatear centro_costo: ENE 2025, ABR 2025, etc.
            $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
            $fecha = Carbon::parse($requestData['request_date']);
            $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;

            $fechaObj = Carbon::parse($requestData['request_date']);
            $mesServicio = $fechaObj->format('Y-m') . '-01'; // Formato: YYYY-MM-01 (primer día del mes)

            CajaChica::create([
                'FECHA' => $requestData['request_date'],
                'CODIGO' => 'CAJA CHICA ' . $uniqueId,
                'DESCRIPCION' => $requestData['note'],
                'SALDO' => $requestData['amount'],
                'CENTRO COSTO' => $centroCosto,
                'CUENTA' => $numeroCuenta,
                'NOMBRE DE CUENTA' => $nombreCuenta,
                'PROVEEDOR' => $requestData['type'] === "expense" ? 'CAJA CHICA' : "DESCUENTOS",
                'EMPRESA' => 'SERSUPPORT',
                'PROYECTO' => $proyecto,
                'I_E' => 'EGRESO',
                'MES SERVICIO' => $mesServicio,
                'TIPO' => $requestData['type'] === "expense" ? "GASTO" : "DESCUENTO",
                'ESTADO' => $requestData['status'],
            ]);

            // Log::debug('Registro en CajaChica creado con éxito');
        } catch (Exception $e) {
            Log::error('Error al crear registro en CajaChica:', [
                'uniqueId' => $uniqueId,
                'error' => $e->getMessage()
            ]);
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

            $reposicionId = $requestModel->reposicion_id;
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

            // Luego recalculamos el total de la reposición asociada
            $this->updateReposicion($reposicionId);

            // Refrescar para obtener los nuevos datos
            $requestModel->refresh();

            // Actualizar caja chica
            $this->updateCajaChicaRecord($requestModel);

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
     * @param $id El id de la rposición a la que corresponde el registro para actualizar el valor total
     * @param $request_value El valor del registro a sumar al total
     * @return void
     */
    private function updateReposicion($id)
    {
        $requests = Request::where('reposicion_id', $id)->get();

        $sum = 0;
        foreach ($requests as $request) {
            $sum += floatval($request->amount);
        }

        Reposicion::where('id', $id)->update([
            'total_reposicion' => $sum,
            'updated_at' => Carbon::now(),
        ]);

        // Log::info("Reposición #$id actualizada con total: $sum");
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
            $cajaChica = CajaChica::where('CODIGO', "LIKE", 'CAJA CHICA ' . "%" . $requestModel->unique_id . "%")->first();

            if ($cajaChica) {
                // Log::debug('Actualizando registro en CajaChica:', [
                //     'uniqueId' => $requestModel->unique_id,
                //     'request_date' => $requestModel->request_date,
                //     'type' => $requestModel->type,
                //     'amount' => $requestModel->amount
                // ]);

                $numeroCuenta = Account::where('name', $requestModel->account_id)->pluck('account_number')->first();
                $nombreCuenta = strtoupper(\Illuminate\Support\Str::ascii($requestModel->account_id)); // sin tildes
                $proyecto = strtoupper($requestModel->project);

                // Formatear centro_costo: ENE 2025, ABR 2025, etc.
                $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
                $fecha = Carbon::parse($requestModel->request_date);
                $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;

                $fechaObj = Carbon::parse($requestModel['request_date']);
                $mesServicio = $fechaObj->format('Y-m') . '-01'; // Formato: YYYY-MM-01 (primer día del mes)

                // Actualizar el registro en CajaChica
                $cajaChica->update([
                    'FECHA' => $requestModel->request_date,
                    'DESCRIPCION' => $requestModel->note,
                    'SALDO' => $requestModel->amount,
                    'CENTRO COSTO' => $centroCosto,
                    'CUENTA' => $numeroCuenta,
                    'NOMBRE DE CUENTA' => $nombreCuenta,
                    'PROVEEDOR' => $requestModel->type === "expense" ? 'CAJA CHICA' : "DESCUENTOS",
                    'EMPRESA' => 'SERSUPPORT',
                    'PROYECTO' => $proyecto,
                    'I_E' => 'EGRESO',
                    'MES SERVICIO' => $mesServicio,
                    'TIPO' => $requestModel->type === "expense" ? "GASTO" : "DESCUENTO",
                    'ESTADO' => $requestModel->status,
                ]);

                // Log::debug('Registro en CajaChica actualizado con éxito');
            } else {
                Log::warning('No se encontró registro en CajaChica para el ID:', [
                    'uniqueId' => $requestModel->unique_id
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error al actualizar registro en CajaChica:', [
                'uniqueId' => $requestModel->unique_id,
                'error' => $e->getMessage()
            ]);
        }
    }


    public function destroy(HttpRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            // Buscar explícitamente por unique_id
            $requestRecord = Request::where('unique_id', $id)->firstOrFail();

            // Eliminar registro relacionado en CajaChica
            CajaChica::where('codigo', 'CAJA CHICA ' . $requestRecord->unique_id)->delete();

            // Eliminar solicitud
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

            // Eliminar registros relacionados en CajaChica
            foreach ($requestIds as $id) {
                CajaChica::where('codigo', 'LIKE', "CAJA CHICA %{$id}")
                    ->orWhere('CODIGO', 'LIKE', "CAJA CHICA %{$id}")
                    ->delete();
            }

            // Eliminar solicitudes
            Request::whereIn('unique_id', $requestIds)->delete();

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
