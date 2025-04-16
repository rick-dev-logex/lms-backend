<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Transport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransportController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('action') === 'count') {
            $vehicles = DB::connection('tms1')->table('vehiculos')->where('status', 'ACTIVO')->get()->count();
            return response()->json($vehicles);
        } else {
            // Subconsulta para tms1.vehiculos
            $tmsQuery = DB::connection('tms1')
                ->table('vehiculos')
                ->select('id', 'name')
                ->selectRaw("REGEXP_REPLACE(UPPER(name), '[^A-Z0-9]', '') AS normalized_name")
                ->where('status', 'ACTIVO');

            // Subconsulta para sistema_onix.onix_vehiculos
            $onixQuery = DB::connection('sistema_onix')
                ->table('onix_vehiculos')
                ->select('id', 'name')
                ->selectRaw("REGEXP_REPLACE(UPPER(name), '[^A-Z0-9]', '') AS normalized_name")
                ->where('deleted', 0);

            // Ejecutar ambas consultas por separado
            $tmsResults = $tmsQuery->get()->toArray();
            $onixResults = $onixQuery->get()->toArray();

            // Combinar resultados y eliminar duplicados basados en normalized_name
            $combinedResults = collect(array_merge($tmsResults, $onixResults))
                ->unique('normalized_name')
                ->map(function ($item) {
                    // Excluir normalized_name del resultado final
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all();

            // Manejar campos específicos si se solicitan
            if ($request->filled('fields')) {
                $fields = explode(',', $request->fields);
                $validFields = array_intersect($fields, ['id', 'name']);
                if (!empty($validFields)) {
                    $combinedResults = array_map(function ($item) use ($validFields) {
                        return array_intersect_key($item, array_flip($validFields));
                    }, $combinedResults);
                }
            }

            return response()->json($combinedResults);
        }
    }

    public function store(Request $request)
    {
        $transport = Transport::create($request->all());
        return response()->json($transport, 201);
    }

    public function show($id)
    {
        $transport = Transport::findOrFail($id);
        return response()->json($transport);
    }

    public function update(Request $request, $id)
    {
        $transport = Transport::findOrFail($id);
        $transport->update($request->all());
        return response()->json($transport, 200);
    }

    public function destroy($id)
    {
        $transport = Transport::findOrFail($id);
        $transport->delete();
        return response()->json(['message' => 'Transport deleted successfully'], 204);
    }

    public function getTransportByAccountId($accountId)
    {
        $transport = Transport::where('account_id', $accountId)->get();
        return response()->json($transport);
    }
}
