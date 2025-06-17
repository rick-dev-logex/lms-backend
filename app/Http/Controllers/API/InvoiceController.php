<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Audit;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }
    // Listado con filtros
    public function index(Request $request)
    {
        $query = Invoice::query();

        if ($request->filled('empresa')) {
            $query->where('empresa', $request->empresa);
        }
        if ($request->filled('mes')) {
            $query->where('mes', (int)$request->mes);
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('desde') && $request->filled('hasta')) {
            $desde = Carbon::parse($request->desde)->startOfDay();
            $hasta = Carbon::parse($request->hasta)->endOfDay();
            $query->whereBetween('fecha_emision', [$desde, $hasta]);
        }

        $facturas = $query->with('notes')->orderByDesc('fecha_emision')->get();

        return response()->json(['success' => true, 'data' => $facturas]);
    }

    // Mostrar una factura
    public function show($id)
    {
        $factura = Invoice::with('notes')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $factura]);
    }

    // Actualizar factura existente
    public function update(Request $request, $id)
    {
        $user    = $this->authService->getUser($request);
        $factura = Invoice::findOrFail($id);

        $data = $request->validate([
            'project'       => 'nullable|string',
            'centro_costo'  => 'nullable|string',
            'notas'         => 'nullable|string',
            'observacion'   => 'nullable|string',
            'contabilizado' => 'boolean',
            'serie_factura' => 'nullable|string',
            'tipo'          => 'nullable|string',
        ]);

        // extrae valores antiguos y cambios
        $original = $factura->getOriginal();
        $factura->update($data);
        $changes = array_intersect_key($data, array_diff_assoc($data, $original));

        activity('invoice')
            ->performedOn($factura)
            ->causedBy($user)
            ->event('updated')
            ->withProperties([
                'old'        => array_intersect_key($original, $changes),
                'attributes' => $changes,
                'updated_by' => $user->name,
                'update_date' => now()->format('d-m-Y'),
                'update_time' => now()->format('H:i:s'),
            ])
            ->log('updated');

        return response()->json(['success' => true, 'data' => $factura]);
    }


    // Eliminar (soft delete o delete permanente)
    public function destroy(Request $request, $id)
    {
        $user    = $this->authService->getUser($request);
        $factura = Invoice::findOrFail($id);

        // capturamos todos los valores antes de borrarlo
        $original = $factura->getOriginal();

        // realizamos el soft-delete
        $factura->delete();

        // log de borrado, sólo una entrada
        activity('invoice')
            ->performedOn($factura)
            ->causedBy($user)
            ->event('deleted')
            ->withProperties([
                'old' => $original,
                'deleted_by' => $user->name,
                'date_deleted' => now()->format('d-m-Y'),
                'time_deleted' => now()->format('H:i:s'),
            ])
            ->log('deleted');

        return response()->json(['success' => true]);
    }
}
