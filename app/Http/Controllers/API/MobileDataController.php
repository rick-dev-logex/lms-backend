<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;

class MobileDataController extends Controller
{
    public function index(HttpRequest $httpRequest, $cedula = null)
    {
        $user = $httpRequest->attributes->get('endpoint_user');

        // Check if cedula is provided
        if (!$cedula) {
            return response()->json([
                'error' => 'Necesitas mandar como parámetro un número de cédula válido registrado en Sistema Onix.'
            ], 400);
        }

        // Query the database
        try {
            $user = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->where('name', $cedula)
                ->first();

            if (!$user) {
                return response()->json([
                    'error' => 'El número de identificación está incorrecto o no existe en la base de datos.'
                ], 404);
            }

            // Obtener los descuentos asignados a este usuario
            $requestResponsable = Request::where('responsible_id', $user->nombre_completo)->get();

            $descuentos = $requestResponsable->map(function ($item) {
                $reposition = Reposicion::where('id', $item->reposicion_id)->first();

                if (!$reposition) {
                    return null; // omite si no encuentra reposición
                }

                return [
                    'tipo' => $item->personnel_type,
                    'fechaRegistro' => $item->created_at,
                    'valor' => $item->amount,
                    'numTransporte' => $item->vehicle_number,
                    'proyecto' => $item->project,
                    'observacion' => $item->note,
                    'estado' => $reposition->status,
                    'placa' => $item->vehicle_plate,
                    'mesRol' => $item->month,
                ];
            })->filter()->values(); // Limpia nulos y reindexa


            $data = [
                'success' => 'true',
                'status' => 200,
                'descuentos' => $descuentos,
            ];

            return response()->json(['data' => $data]);
        } catch (\Exception $error) {
            return response()->json([
                'error' => 'El número de identificación está incorrecto o no existe en la base de datos.'
            ], 500);
        }
    }

    // public function store(Request $request)
    // {
    //     $user = $request->attributes->get('endpoint_user');
    //     $data = $request->validate([
    //         'some_field' => 'required|string'
    //     ]);

    //     // Lógica para guardar datos
    //     return response()->json(['message' => 'Datos guardados']);
    // }
}
