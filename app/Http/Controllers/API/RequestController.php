<?php

namespace App\Http\Controllers\API;

use App\Events\RequestUpdated;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Request;
use App\Notifications\RequestNotification;
use App\Services\PersonnelService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    private $personnelService;

    public function __construct(PersonnelService $personnelService)
    {
        $this->personnelService = $personnelService;
    }

    public function index(HttpRequest $request)
    {
        if ($request->input('action') === 'count') {
            return $this->handleCountRequest($request);
        }

        try {
            // Iniciar la consulta base
            $query = Request::query();

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

            // Aplicar filtros
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Ordenamiento
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['unique_id', 'created_at', 'updated_at', 'amount', 'project', 'status'];
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // Solo cargar la relación account que está en la misma base de datos
            $query->with(['account:id,name']);

            // Paginación
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            // Obtener IDs únicos para responsible y transport
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

            // Obtener datos de la base de datos externa
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

            // Mapear los datos relacionados a cada request
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

    private function handleCountRequest(HttpRequest $request)
    {
        $status = $request->status;
        $query = Request::query();

        if ($request->boolean('currentMonth')) {
            return $this->getCurrentMonthCounts($query, $status);
        }

        if ($request->has('year')) {
            return $this->getYearlyCounts($query, $status, $request->year);
        }

        return response()->json($query->where('status', $status)->count());
    }

    private function getCurrentMonthCounts($query, $status)
    {
        try {
            $currentMonth = now()->month;
            $currentYear = now()->year;
            $currentDay = now()->day;

            $counts = DB::table('requests')
                ->select(DB::raw('DAY(created_at) as day, COUNT(*) as count'))
                ->where('status', $status)
                ->whereMonth('created_at', $currentMonth)
                ->whereYear('created_at', $currentYear)
                ->groupBy('day')
                ->get()
                ->pluck('count', 'day');

            return response()->json(
                collect(range(1, $currentDay))->map(fn($day) => $counts[$day] ?? 0)
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener conteos mensuales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getYearlyCounts($query, $status, $year)
    {
        try {
            $counts = DB::table('requests')
                ->select(DB::raw('MONTH(created_at) as month, COUNT(*) as count'))
                ->where('status', $status)
                ->whereYear('created_at', $year)
                ->groupBy('month')
                ->get()
                ->pluck('count', 'month');

            return response()->json(
                collect(range(1, 12))->map(fn($month) => $counts[$month] ?? 0)
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener conteos anuales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(HttpRequest $request, $id)
    {
        if ($request->input('action') === 'download') {
            return $this->getFile($id);
        }

        $request->has('status') ? $requests = Request::where('status', $request->status)->get() : $requests = Request::all();

        return response()->json($requests->load(['account', 'project', 'responsible', 'transport']));
    }

    public function getFile($id)
    {
        $request = Request::find($id);

        if (!$request) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        if (!$request->attachment_path) {
            return response()->json(['message' => 'No attachment found for this request'], 404);
        }

        $filePath = $request->attachment_path;

        if (!Storage::exists($filePath)) {
            return response()->json(['message' => 'File not found in storage'], 404);
        }

        $mimeType = Storage::mimeType($filePath);
        $fileName = basename($filePath);

        return response()->streamDownload(function () use ($filePath) {
            echo Storage::get($filePath);
        }, $fileName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"'
        ]);
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
                'attachment' => 'required|file',
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
                'unique_id' => $unique_id,
                'attachment_path' => $request->file('attachment')->storeAs('attachments', $request->file('attachment')->getClientOriginalName())
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
            $responsible->notify(new RequestNotification($requestRecord, $validated['status']));
        }

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $responsible = $requestRecord->responsible;

            if ($validated['status'] === 'paid') {
                $responsible->notify(new RequestNotification($requestRecord, 'paid'));
            } elseif ($validated['status'] === 'rejected') {
                $responsible->notify(new RequestNotification($requestRecord, 'rejected'));
            } elseif ($validated['status'] === 'in_reposition') {
                $responsible->notify(new RequestNotification($requestRecord, 'in_reposition'));
            } elseif ($validated['status'] === 'review') {
                $responsible->notify(new RequestNotification($requestRecord, 'review'));
            } elseif ($validated['status'] === 'pending') {
                $responsible->notify(new RequestNotification($requestRecord, 'pending'));
            }
        }

        return response()->json($requestRecord);
    }

    public function destroy(Request $requestRecord)
    {
        $requestRecord->delete();
        return response()->json(['message' => 'Request deleted successfully.']);
    }

    // Importar descuentos desde Excel
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
                    // Normalizar el tipo de personal (convertir a minúsculas y quitar tildes)
                    $personnelType = $this->normalizePersonnelType($discount['Tipo']);
                    if (!in_array($personnelType, ['nomina', 'transportista'])) {
                        throw new \Exception("Tipo de personal inválido: {$discount['Tipo']}. Debe ser 'Nómina/nomina' o 'Transportista/transportista'");
                    }

                    // Validar y obtener el ID del proyecto
                    $projectId = $this->getProjectId($discount['Proyecto']);

                    // Validar y obtener ID del responsable según el tipo
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

                    // Mapear campos del Excel a la base de datos
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

                    // Validar datos mapeados
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

                    // Añadir reglas condicionales según el tipo de personal
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

                    // Generar identificador único
                    $prefix = 'D-';
                    $lastRecord = Request::where('type', 'discount')
                        ->orderBy('id', 'desc')
                        ->first();
                    $nextId = $lastRecord ?
                        ((int)str_replace(
                            $prefix,
                            '',
                            $lastRecord->unique_id
                        ) + 1) : 1;
                    $mappedData['unique_id'] = $prefix . $nextId;

                    // Crear el registro
                    Request::create($mappedData);
                    $processedCount++;
                } catch (\Exception $e) {
                    // Guardamos el error con información de contexto
                    $errors[] = [
                        'row' => $index + 2, // +2 porque Excel empieza en 1 y tiene cabecera
                        'data' => $discount, // Guardamos los datos de la fila para contexto
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
