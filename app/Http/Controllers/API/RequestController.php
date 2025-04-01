<?php

namespace App\Http\Controllers\API;

use App\Events\RequestUpdated;
use App\Http\Controllers\Controller;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\Project;
use App\Models\Request;
use App\Models\User;
use App\Notifications\RequestNotification;
use App\Services\PersonnelService;
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
                throw new \Exception("No se encontró el token de autenticación en la cookie.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;

            if (!$userId) {
                throw new \Exception("No se encontró el ID de usuario en el token JWT.");
            }

            $import = new RequestsImport($context, $userId);
            $excel->import($import, $file);

            if (!empty($import->errors)) {
                throw new \Exception(json_encode($import->errors));
            }

            DB::commit();
            return response()->json(['message' => 'Importación exitosa'], 200);
        } catch (\Exception $e) {
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
                throw new \Exception("No se encontró el token de autenticación en la cookie.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;
            if (!$userId) {
                throw new \Exception("No se encontró el ID de usuario en el token JWT.");
            }

            // Obtener usuario y sus proyectos asignados
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception("Usuario no encontrado.");
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
                $query->whereIn('project', $projectNames); // Filter by names instead of UUIDs
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
            $requests = $query->get();

            // Log for debugging
            Log::info('RequestController::index', [
                'user_id' => $userId,
                'assigned_project_uuids' => $assignedProjectIds,
                'project_names' => $projectNames,
                'request_count' => $requests->count(),
            ]);

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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
                'type' => 'required|in:expense,discount',
                'personnel_type' => 'required|in:nomina,transportista',
                'request_date' => 'required|date',
                'invoice_number' => 'required|numeric',
                'account_id' => 'required|exists:accounts,name',
                'amount' => 'required|numeric|min:0',
                'project' => 'required|string',
                'note' => 'required|string',
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,nombre_completo';
            } else {
                $baseRules['vehicle_plate'] = 'required|exists:sistema_onix.onix_vehiculos,name';
            }

            $validated = $request->validate($baseRules);

            // Generar el identificador único
            $prefix = $validated['type'] === 'expense' ? 'G-' : 'D-';
            $lastRecord = Request::where('type', $validated['type'])->orderBy('id', 'desc')->first();
            $nextId = $lastRecord ? ((int)str_replace($prefix, '', $lastRecord->unique_id) + 1) : 1;
            $uniqueId = $nextId <= 9999 ? sprintf('%s%05d', $prefix, $nextId) : sprintf('%s%d', $prefix, $nextId);

            $requestData = [
                'type' => $validated['type'],
                'personnel_type' => $validated['personnel_type'],
                'project' => $request['project'], // Guardar el nombre
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'vehicle_number' => $request['vehicle_number'],
                'note' => $validated['note'],
                'unique_id' => $uniqueId,
                'status' => 'pending'
            ];

            if (isset($validated['responsible_id'])) {
                $requestData['responsible_id'] = $validated['responsible_id'];
            }
            if (isset($validated['vehicle_plate'])) {
                $requestData['vehicle_plate'] = $validated['vehicle_plate'];
            }

            $newRequest = Request::create($requestData);

            return response()->json([
                'message' => 'Request created successfully',
                'data' => $newRequest
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating request',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function update(HttpRequest $request, Request $requestRecord)
    {
        try {
            $baseRules = [
                'status' => 'sometimes|in:pending,paid,rejected,review,in_reposition',
                'request_date' => 'sometimes|date',
                'invoice_number' => 'sometimes|numeric',
                'amount' => 'sometimes|numeric',
                'project' => 'sometimes|string', // Nombre del proyecto desde el frontend
                'vehicle_number' => 'sometimes|string',
                'note' => 'sometimes|string',
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'sometimes|exists:sistema_onix.onix_personal,id';
            } else {
                $baseRules['vehicle_plate'] = 'sometimes|exists:sistema_onix.onix_vehiculos,name';
            }

            $validated = $request->validate($baseRules);

            // Si se envía un proyecto, convertirlo a UUID
            if (isset($validated['project'])) {
                $projectName = trim($validated['project']);
                $project = Project::where('name', $projectName)->first();
                if (!$project) {
                    throw new \Exception("Proyecto no encontrado: {$projectName}");
                }
                $validated['project'] = $project->id;
            }

            $oldStatus = $requestRecord->status;

            $requestRecord->update($validated);

            if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
                $responsible = $requestRecord->responsible;
                broadcast(new RequestUpdated($requestRecord))->toOthers();

                if ($responsible) {
                    $responsible->notify(new RequestNotification($requestRecord, $validated['status']));
                }
            }

            return response()->json($requestRecord);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la solicitud',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function destroy(Request $requestRecord)
    {
        $requestRecord->delete();
        return response()->json(['message' => 'Request deleted successfully']);
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
                        throw new \Exception("Tipo de personal inválido: {$discount['Tipo']}. Debe ser 'Nómina/nomina' o 'Transportista/transportista'");
                    }

                    $projectName = trim($discount['Proyecto']);
                    $project = Project::where('name', $projectName)->first();
                    if (!$project) {
                        throw new \Exception("Proyecto no encontrado: {$projectName}");
                    }

                    if ($personnelType === 'nomina') {
                        $responsibleId = $this->getResponsibleId($discount['Responsable']);
                    } else {
                        if (!isset($discount['Placa']) || empty($discount['Placa'])) {
                            throw new \Exception("La placa del vehículo es requerida para transportistas");
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
                        'responsible_id' => $responsibleId,
                        'vehicle_plate' => $transportId,
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

                    if ($personnelType === 'nomina') {
                        $validator->addRules([
                            'responsible_id' => 'required|exists:sistema_onix.onix_personal,id'
                        ]);
                    } else {
                        $validator->addRules([
                            'vehicle_plate' => 'required|exists:sistema_onix.onix_vehiculos,name'
                        ]);
                    }

                    if ($validator->fails()) {
                        $errorMessages = $validator->errors()->all();
                        throw new \Exception("Error en la fila " . ($index + 2) . ": " . implode(", ", $errorMessages));
                    }

                    $prefix = 'D-';
                    $lastRecord = Request::where('type', 'discount')
                        ->orderBy('id', 'desc')
                        ->first();
                    $nextId = $lastRecord ?
                        ((int)str_replace($prefix, '', $lastRecord->unique_id) + 1) : 1;
                    $mappedData['unique_id'] = $nextId <= 9999 ? sprintf('%s%05d', $prefix, $nextId) : sprintf('%s%d', $prefix, $nextId);

                    Request::create($mappedData);
                    $processedCount++;
                } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
            throw new \Exception("Cuenta no encontrada: {$accountName}");
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
            throw new \Exception("Responsable no encontrado: {$responsibleName}");
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
            throw new \Exception("Vehículo no encontrado con placa: {$plate}");
        }
        return $transport->name;
    }
}
