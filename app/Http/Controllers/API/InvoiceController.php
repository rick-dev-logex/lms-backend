<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function index(Request $request)
    {
        $query = Invoice::query();

        if ($request->filled('empresa')) {
            $query->where('identificacion_comprador', $request->empresa);
        }
        if ($request->filled('mes')) {
            $query->where('mes', (int)$request->mes);
        }
        if ($request->filled('cuenta_contable')) {
            $query->where('cuenta_contable', $request->cuenta_contable);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->ready == "true") {
            $query->where('contabilizado', 'CONTABILIZADO');
        } else {
            $query->where('contabilizado', 'PENDIENTE');
        }

        if ($request->filled('desde') && $request->filled('hasta')) {
            $desde = Carbon::parse($request->desde)->startOfDay();
            $hasta = Carbon::parse($request->hasta)->endOfDay();
            $query->whereBetween('fecha_emision', [$desde, $hasta]);
        }

        $facturas = $query->with(['notes', 'details'])->orderByDesc('fecha_emision')->get();

        return response()->json(['success' => true, 'data' => $facturas]);
    }

    // Mostrar una factura
    public function show($id)
    {
        $factura = Invoice::with(['notes', 'details'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $factura]);
    }

    /**
     * Actualiza los datos de la factura
     */
    public function update(Request $request, $id)
    {
        $user    = $this->authService->getUser($request);
        $factura = Invoice::findOrFail($id);

        // 1. Validación de todos los campos
        $data = $request->validate([
            'project'            => 'nullable|string',
            'centro_costo'       => 'nullable|string',
            'notas'              => 'nullable|string',
            'observacion'        => 'nullable|string',
            'contabilizado'      => 'nullable|string',
            'tipo'               => 'nullable|string',
            'secuencial'         => 'nullable|string',
            'proveedor_latinium' => 'nullable|string',
            'cuenta_contable'    => 'sometimes|nullable|string',
        ]);

        // 2. Si están todos los requeridos, armar nota_latinium
        if (
            !empty($data['project'] ?? $factura->project) &&
            !empty($data['cuenta_contable'] ?? $factura->cuenta_contable) &&
            !empty($data['observacion'] ?? $factura->observacion) &&
            !empty($data['secuencial']) &&
            !empty($data['centro_costo'] ?? $factura->centro_costo) &&
            !empty($data['proveedor_latinium'])
        ) {
            $cuenta = $data['cuenta_contable']
                ?? $factura->cuenta_contable;
            $data['nota_latinium'] = implode(' / ', [
                $data['project'],
                $cuenta,
                $data['observacion'],
                $data['secuencial'],
                $data['centro_costo'],
                $data['proveedor_latinium'],
            ]);
        }

        // 3. Guardar TODO de una vez
        $original = $factura->getOriginal();
        $factura->update($data);

        // 4. Registrar cambios en el log
        $changes = array_intersect_key($data, array_diff_assoc($data, $original));
        activity('invoice')
            ->performedOn($factura)
            ->causedBy($user)
            ->event('updated')
            ->withProperties([
                'old'         => array_intersect_key($original, $changes),
                'attributes'  => $changes,
                'updated_by'  => $user->name,
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

    /**
     * Get Latinium projects
     */
    public function latiniumProjects()
    {
        $projects = DB::connection('latinium')
            // especificamos el schema dbo y la tabla Proyecto_rs
            ->table('dbo.Proyecto_rs AS pr')
            // unimos con SubProyecto para obtener el nombre
            ->join('dbo.SubProyecto AS sp', 'pr.idSubProyecto', '=', 'sp.idSubProyecto')
            ->select([
                'pr.CodSubproyecto AS code',
                'sp.Nombre          AS name',
            ])->where('visible', '1')
            ->orderBy('pr.CodSubproyecto')
            ->get();

        // Para devolverlo listo para un <select> en formato { value: name, text: code }:
        $formatted = $projects->map(fn($p) => [
            'value' => $p->name,
            'label'  => $p->code,
        ]);

        return response()->json([
            'data' => $formatted,
        ]);
    }

    /**
     * Trae el centro de costo (todos los del año en curso)
     */
    public function centroCosto()
    {
        $currentYear = now()->year;

        $values = DB::connection('latinium')
            ->table('dbo.Proyecto')
            ->select('nombre')
            ->where('nombre', 'like', "% {$currentYear}")
            ->orderByRaw("CHARINDEX(LEFT(nombre,3), 'ENE,FEB,MAR,ABR,MAY,JUN,JUL,AGO,SEP,OCT,NOV,DIC')")
            ->get()
            ->map(function ($item) {
                return [
                    'label' => $item->nombre,
                    'value' => $item->nombre,
                ];
            });

        return response()->json(['data' => $values]);
    }

    /**
     * Actualiza el campo proveedor_latinium en invoices
     * para todos los registros donde aún es NULL.
     */
    public function actualizarProveedoresLatinium(Request $request)
    {
        $source     = strtoupper($request->get('source', 'PREBAM'));
        // Elegimos la conexión, no intervenimos la tabla
        $connection = $source === 'PREBAM'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        // Sólo RUCs de las facturas importadas de esa fuente
        $rucs = Invoice::whereNull('proveedor_latinium')
            ->pluck('ruc_emisor')
            ->unique();

        $mapeo    = [];
        $faltantes = [];

        foreach ($rucs as $ruc) {
            // Usamos la conexión dinámica y sin prefijo de DB
            $nombre = DB::connection($connection)
                ->table('dbo.Cliente')
                ->where('ruc', $ruc)
                ->value('Nombre');

            if (!$nombre) {
                $faltantes[] = $ruc;
            } else {
                $mapeo[$ruc] = $nombre;
            }
        }

        if ($faltantes) {
            return response()->json([
                'error' => "Aún no se ha registrado el proveedor para el/los RUC: "
                    . implode(', ', $faltantes)
            ], 422);
        }

        foreach ($mapeo as $ruc => $nombre) {
            Invoice::where('source', $source)
                ->where('ruc_emisor', $ruc)
                ->update(['proveedor_latinium' => $nombre]);
        }

        return response()->json([
            'message' => "Proveedores actualizados correctamente para {$source}"
        ]);
    }

    /**
     * Actualiza el campo proveedor_latinium en invoices
     * para todos los registros donde aún es NULL.
     */
    public function actualizarEstadoContableLatinium(Request $request)
    {
        $facturas = $request->input('facturas', []);

        foreach ($facturas as $factura) {
            $connection = $factura['identificacion_comprador'] === "0992301066001"
                ? 'latinium_prebam'
                : 'latinium_sersupport';

            $rucEmisor   = $factura['ruc_emisor'] ?? null;
            $claveAcceso = $factura['clave_acceso'] ?? null;

            if (!$rucEmisor || !$claveAcceso) {
                continue;
            }

            $cliente = DB::connection($connection)
                ->table('Cliente')
                ->select('idCliente')
                ->where('Ruc', $rucEmisor)
                ->first();

            $idCliente = $cliente->idCliente ?? null;

            $isContabilizado = $idCliente
                ? DB::connection($connection)
                ->table('Compra')
                ->where('idCliente', $idCliente)
                ->where('AutFactura', $claveAcceso)
                ->exists()
                : false;

            $estadoContable = $isContabilizado ? 'CONTABILIZADO' : 'PENDIENTE';
            $estadoContableLatinium = $isContabilizado ? 'contabilizado' : 'pendiente';

            // Actualizar en la base de datos local
            Invoice::where('id', $factura['id'])->update([
                'contabilizado' => $estadoContable,
                'estado_latinium' => $estadoContableLatinium
            ]);
        }

        return response()->json(['message' => 'Sincronización manual completa']);
    }

    /**
     * Devuelve las cuentas contables para el ComboBox.
     */
    public function latiniumAccounts(Request $request)
    {
        $source     = strtoupper($request->query('source', 'PREBAM'));
        $connection = $source === 'PREBAM'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        $accounts = DB::connection($connection)
            ->table('dbo.ARTICULO')
            ->select([
                'Codigo as value',   // value para el ComboBox
                'Articulo as label', // label visible
            ])
            ->get();

        return response()->json(['data' => $accounts]);
    }
    // No permitir editar, solo se pueden Claudia, John y Nico 
}
