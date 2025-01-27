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
        $query = Reposicion::query();

        // Utilizamos los nuevos scopes
        if ($request->has('project')) {
            $query->byProject($request->project);
        }

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('month')) {
            $query->byMonth($request->month);
        }

        // Cargamos las relaciones usando el nuevo método requests()
        return response()->json(
            $query->with(['requests' => function ($query) {
                $query->with(['account', 'responsible', 'transport']);
            }])->get()
        );
    }

    public function store(HttpRequest $request)
    {
        try {
            DB::beginTransaction();

            // Validar la entrada
            $validated = $request->validate([
                'request_ids' => 'required|array',
                'request_ids.*' => 'exists:requests,unique_id',
                'month' => 'required|string', // formato: "2025-01"
                'when' => 'required|in:rol,liquidación,decimo_tercero,decimo_cuarto,utilidades',
                'note' => 'required|string',
            ]);

            // Obtener las solicitudes y verificar que sean del mismo proyecto
            // Usamos el nuevo scope byProject
            $requests = Request::whereIn('unique_id', $validated['request_ids'])
                ->get();

            if ($requests->isEmpty()) {
                throw new \Exception('No requests found with the provided IDs');
            }

            $project = $requests->first()->project;

            if ($requests->pluck('project')->unique()->count() > 1) {
                throw new \Exception('All requests must belong to the same project');
            }

            // Crear la reposición usando los nuevos casts
            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $requests->sum('amount'),
                'status' => 'pending',
                'project' => $project,
                'detail' => $validated['request_ids'], // Ahora se castea automáticamente a JSON
                'month' => $validated['month'],
                'when' => $validated['when'],
                'note' => $validated['note']
            ]);

            // Actualizar el estado de las solicitudes relacionadas
            Request::whereIn('unique_id', $validated['request_ids'])
                ->update([
                    'status' => 'in_reposition'
                ]);

            DB::commit();

            // Cargar las relaciones usando el nuevo método requests()
            return response()->json([
                'message' => 'Reposición created successfully',
                'data' => $reposicion->load('requests')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function show($id)
    {
        // Usar las nuevas relaciones para cargar los datos
        $reposicion = Reposicion::with(['requests' => function ($query) {
            $query->with(['account', 'responsible', 'transport']);
        }])->findOrFail($id);

        // Agregar el total calculado usando el nuevo método
        $reposicion->calculated_total = $reposicion->calculateTotal();

        return response()->json($reposicion);
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
