<?php

namespace App\Http\Controllers\API;

use App\Events\RequestUpdated;
use App\Http\Controllers\Controller;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\Request;
use App\Notifications\RequestNotification;
use App\Services\PersonnelService;
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
            $userId = auth()->id();

            Log::info('Archivo recibido: ' . $file->getClientOriginalName());
            Log::info('Contexto: ' . $context);

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
            $errors = json_decode($e->getMessage(), true) ?? [$e->getMessage()];
            return response()->json(['errors' => $errors], 400);
        }
    }

    public function index(HttpRequest $request)
    {
        try {
            // Obtener el usuario autenticado
            $user = auth()->user();
            $assignedProjectIds = [];

            if ($user && $user->assignedProjects) {
                $assignedProjectIds = $user->assignedProjects->projects;
            }

            // Iniciar la consulta base
            $query = Request::query();

            // Filtrar por proyectos asignados
            if (!empty($assignedProjectIds)) {
                $query->whereIn('project', $assignedProjectIds);
            }

            // Aplicar búsqueda global
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('unique_id', 'like', "%{$searchTerm}%")
                        ->orWhere('project', 'like', "%{$searchTerm}%")
                        ->orWhere('invoice_number', 'like', "%{$searchTerm}%")
                        ->orWhere('amount', 'like', "%{$searchTerm}%");
                });
            }

            // Aplicar filtros adicionales
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('status')) {
                if ($request->input('action') === 'count') {
                    return response()->json(
                        Request::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)
                            ->where('status', $request->status)
                            ->count()
                    );
                }
                $query->where('status', $request->status);
            }

            // Ordenamiento
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['unique_id', 'created_at', 'updated_at', 'amount', 'project', 'status'];
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // Cargar la relación 'account'
            $query->with(['account:id,name']);

            // Paginación
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            // Obtener IDs únicos para 'responsible' y 'transport'
            $responsibleIds = collect($paginator->items())
                ->pluck('responsible_id')
                ->filter()
                ->unique()
                ->values();

            $transportIds = collect($paginator->items())
                ->pluck('transport_id')
                ->filter()
                ->unique()
                ->values();

            // Obtener datos externos desde sistema_onix
            $responsibles = [];
            $transports = [];

            if ($responsibleIds->isNotEmpty()) {
                $responsibles = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->whereIn('id', $responsibleIds)
                    ->select('id', 'nombre_completo')
                    ->get()
                    ->keyBy('id')
                    ->toArray();
            }
            if ($transportIds->isNotEmpty()) {
                $transports = DB::connection('sistema_onix')
                    ->table('onix_vehiculos')
                    ->whereIn('id', $transportIds)
                    ->select('id', 'name')
                    ->get()
                    ->keyBy('id')
                    ->toArray();
            }

            // Mapear los datos relacionados a cada solicitud
            $data = collect($paginator->items())->map(function ($item) use ($responsibles, $transports) {
                if ($item->responsible_id && isset($responsibles[$item->responsible_id])) {
                    $item->responsible = $responsibles[$item->responsible_id];
                }
                if ($item->transport_id && isset($transports[$item->transport_id])) {
                    $item->transport = $transports[$item->transport_id];
                }
                return $item;
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(HttpRequest $request, $id)
    {
        $request->has('status') ?
            $requests = Request::where('status', $request->status)->get() :
            $requests = Request::all();

        return response()->json($requests->load(['account', 'project', 'responsible', 'transport']));
    }

    public function store(HttpRequest $request)
    {
        try {
            $baseRules = [
                'type' => 'required|in:expense,discount',
                'personnel_type' => 'required|in:nomina,transportista',
                'request_date' => 'required|date',
                'invoice_number' => 'required|string',
                'account_id' => 'required|exists:accounts,id',
                'amount' => 'required|numeric|min:0',
                'project' => 'required|string',
                'note' => 'required|string'
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,id';
            } else {
                $baseRules['transport_id'] = 'required|exists:sistema_onix.onix_vehiculos,id';
            }

            $validated = $request->validate($baseRules);

            // Generar el identificador único incremental con prefijo
            $prefix = $validated['type'] === 'expense' ? 'G-' : 'D-';
            $lastRecord = Request::where('type', $validated['type'])->orderBy('id', 'desc')->first();
            $nextId = $lastRecord ? ((int)str_replace($prefix, '', $lastRecord->unique_id) + 1) : 1;
            $unique_id = $prefix . $nextId;

            $requestData = [
                'type' => $validated['type'],
                'personnel_type' => $validated['personnel_type'],
                'project' => strtoupper($validated['project']),
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'note' => $validated['note'],
                'unique_id' => $unique_id
            ];

            if (isset($validated['responsible_id'])) {
                $requestData['responsible_id'] = $validated['responsible_id'];
            }
            if (isset($validated['transport_id'])) {
                $requestData['transport_id'] = $validated['transport_id'];
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
        $validated = $request->validate([
            'status' => 'sometimes|in:pending,paid,rejected,review,in_reposition',
            'note' => 'sometimes|string',
        ]);

        $oldStatus = $requestRecord->status;
        $requestRecord->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $responsible = $requestRecord->responsible;
            // Disparar el evento de broadcasting
            broadcast(new RequestUpdated($requestRecord))->toOthers();

            // Enviar notificaciones según el estado
            if ($responsible) {
                $responsible->notify(new RequestNotification($requestRecord, $validated['status']));
            }
        }

        return response()->json($requestRecord);
    }

    public function destroy(Request $requestRecord)
    {
        $requestRecord->delete();
        return response()->json(['message' => 'Request deleted successfully.']);
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

                    $projectId = $this->getProjectId($discount['Proyecto']);
                    $responsibleId = null;
                    $transportId = null;

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
                        'project' => $projectId,
                        'responsible_id' => $responsibleId,
                        'transport_id' => $transportId,
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
                            'transport_id' => 'required|exists:sistema_onix.onix_vehiculos,id'
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
                    $mappedData['unique_id'] = $prefix . $nextId;

                    // Crear el registro
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
        // Convertir a minúsculas y quitar tildes
        $normalized = strtolower(trim($type));
        $normalized = str_replace('ó', 'o', $normalized);

        // Mapear variaciones comunes
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
        return $account->id;
    }

    private function getProjectId($projectName)
    {
        $project = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->where('name', $projectName)
            ->first();

        if (!$project) {
            throw new \Exception("Proyecto no encontrado: {$projectName}");
        }
        return $project->id;
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
        return $responsible->id;
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
        return $transport->id;
    }
}
