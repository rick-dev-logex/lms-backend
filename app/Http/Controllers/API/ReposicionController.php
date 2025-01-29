<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReposicionController extends Controller
{
    public function index(HttpRequest $request)
    {
        try {
            $query = Reposicion::query();

            // Aplicar filtros
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('project', 'like', "%{$searchTerm}%")
                        ->orWhere('total_reposicion', 'like', "%{$searchTerm}%")
                        ->orWhere('note', 'like', "%{$searchTerm}%");
                });
            }

            if ($request->filled('project')) {
                $query->byProject($request->project);
            }

            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }

            if ($request->filled('month')) {
                $query->byMonth($request->month);
            }

            // Ordenamiento
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['created_at', 'fecha_reposicion', 'total_reposicion', 'project', 'status'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // Paginación
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            // Obtener todos los unique_ids de las solicitudes
            $allRequestIds = collect($paginator->items())
                ->pluck('detail')
                ->flatten()
                ->unique()
                ->values()
                ->toArray();

            // Cargar todas las solicitudes relacionadas de una vez
            $requests = Request::whereIn('unique_id', $allRequestIds)
                ->with('account:id,name')
                ->get()
                ->keyBy('unique_id');

            // Obtener IDs únicos para responsables y transportes
            $responsibleIds = $requests->pluck('responsible_id')->filter()->unique();
            $transportIds = $requests->pluck('transport_id')->filter()->unique();

            // Cargar datos de la base de datos externa
            $responsibles = $responsibleIds->isNotEmpty()
                ? DB::connection('sistema_onix')
                ->table('onix_personal')
                ->whereIn('id', $responsibleIds)
                ->select('id', 'nombre_completo')
                ->get()
                ->keyBy('id')
                : collect();

            $transports = $transportIds->isNotEmpty()
                ? DB::connection('sistema_onix')
                ->table('onix_vehiculos')
                ->whereIn('id', $transportIds)
                ->select('id', 'name')
                ->get()
                ->keyBy('id')
                : collect();

            // Mapear los datos
            $data = collect($paginator->items())->map(function ($reposicion) use ($requests, $responsibles, $transports) {
                $reposicionRequests = collect($reposicion->detail)->map(function ($requestId) use ($requests, $responsibles, $transports) {
                    $request = $requests->get($requestId);
                    if ($request) {
                        // Agregar datos de responsable y transporte si existen
                        if ($request->responsible_id && $responsibles->has($request->responsible_id)) {
                            $request->responsible = $responsibles->get($request->responsible_id);
                        }
                        if ($request->transport_id && $transports->has($request->transport_id)) {
                            $request->transport = $transports->get($request->transport_id);
                        }
                    }
                    return $request;
                })->filter();

                $reposicion->requests = $reposicionRequests->values();
                return $reposicion;
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
            \Log::error('Error en ReposicionController@index: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $reposicion = Reposicion::findOrFail($id);

        // Cargar las solicitudes con sus relaciones
        $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

        return response()->json($reposicion);
    }

    public function store(HttpRequest $request)
    {
        try {
            DB::beginTransaction();

            // Validar la entrada
            $validated = $request->validate([
                'request_ids' => 'required|array',
                'request_ids.*' => 'exists:requests,unique_id',
            ]);

            // Obtener las solicitudes usando el array directamente
            $requests = Request::whereIn('unique_id', $validated['request_ids'])->get();

            if ($requests->isEmpty()) {
                throw new \Exception('No requests found with the provided IDs');
            }

            $project = $requests->first()->project;

            if ($requests->pluck('project')->unique()->count() > 1) {
                throw new \Exception('All requests must belong to the same project');
            }

            // Crear la reposición
            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $requests->sum('amount'),
                'status' => 'pending',
                'project' => $project,
                'detail' => $validated['request_ids'] // Aquí pasamos directamente el array
            ]);

            // Actualizar el estado de las solicitudes relacionadas
            Request::whereIn('unique_id', $validated['request_ids'])
                ->update(['status' => 'in_reposition']);

            DB::commit();

            // Cargar las solicitudes para la respuesta
            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            return response()->json([
                'message' => 'Reposición created successfully',
                'data' => $reposicion
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function update(HttpRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $reposicion = Reposicion::findOrFail($id);

            $validated = $request->validate([
                'status' => 'sometimes|in:pending,approved,rejected',
                'month' => 'sometimes|string',
                'when' => 'sometimes|in:rol,liquidación,decimo_tercero,decimo_cuarto,utilidades',
                'note' => 'sometimes|string',
            ]);

            // Si se está actualizando el estado
            if (isset($validated['status']) && $validated['status'] !== $reposicion->status) {
                if ($validated['status'] === 'approved') {
                    // Verificar que el total coincida antes de aprobar
                    $calculatedTotal = $reposicion->calculateTotal();
                    if ($calculatedTotal != $reposicion->total_reposicion) {
                        throw new \Exception('Total mismatch between requests and reposicion');
                    }
                } elseif ($validated['status'] === 'rejected') {
                    // Restaurar las solicitudes a estado pendiente
                    Request::whereIn('unique_id', $reposicion->detail)
                        ->update(['status' => 'pending']);
                }
            }

            $reposicion->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Reposición updated successfully',
                'data' => $reposicion->load('requests')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $reposicion = Reposicion::findOrFail($id);

            // Usar la relación requests para actualizar las solicitudes
            Request::whereIn('unique_id', $reposicion->detail)
                ->update(['status' => 'pending']);

            $reposicion->delete();

            DB::commit();

            return response()->json([
                'message' => 'Reposición deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error deleting reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
