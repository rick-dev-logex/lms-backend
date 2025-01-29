<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Request;
use App\Notifications\RequestNotification;
use App\Services\PersonnelService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;

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

    public function show(HttpRequest $request)
    {
        $request->has('status') ? $requests = Request::where('status', $request->status)->get() : $requests = Request::all();
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
                'attachment_path' => $request->file('attachment')->store('attachments')
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
            'status' => 'sometimes|in:pending,approved,rejected,review,in_reposition',
            'note' => 'sometimes|string',
        ]);

        $oldStatus = $requestRecord->status;
        $requestRecord->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $responsible = $requestRecord->responsible;

            if ($validated['status'] === 'approved') {
                $responsible->notify(new RequestNotification($requestRecord, 'approved'));
            } elseif ($validated['status'] === 'rejected') {
                $responsible->notify(new RequestNotification($requestRecord, 'rejected'));
            }
        }

        return response()->json($requestRecord);
    }

    public function destroy(Request $requestRecord)
    {
        $requestRecord->delete();
        return response()->json(['message' => 'Request deleted successfully.']);
    }
}
