<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;

class MobileDataController extends Controller
{
    public function index(HttpRequest $httpRequest)
    {
        $cedula = $httpRequest->input('cedula');

        if (!$cedula) {
            return response()->json([
                'error' => 'Necesitas mandar como parámetro un número de cédula válido registrado en Sistema Onix.'
            ], 400);
        }

        try {
            $usuarioOnix = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->where('name', $cedula)
                ->first();

            if (!$usuarioOnix) {
                return response()->json([
                    'error' => 'El número de identificación está incorrecto o no existe en la base de datos.'
                ], 404);
            }

            $solicitudes = Request::where('responsible_id', $usuarioOnix->nombre_completo)->where('type', 'discount')->get();

            if ($httpRequest->has('mesrol')) {
                $solicitudes = Request::where('responsible_id', $usuarioOnix->nombre_completo)->where('type', 'discount')->where('month', $httpRequest->input('mesrol'))->get();
            }

            $descuentos = $solicitudes->map(function ($item) {
                $reposition = Reposicion::find($item->reposicion_id);

                if (!$reposition || empty($reposition->month)) {
                    return null; // Omite si no hay reposición o si no tiene mes
                }

                return [
                    'tipo' => $item->personnel_type ?? "",
                    'fechaRegistro' => $item->request_date instanceof \DateTime ? $item->request_date->format('Y-m-d') : "",
                    'valor' => $item->amount ?? "",
                    'numTransporte' => $item->vehicle_number ?? "",
                    'proyecto' => $item->project ?? "",
                    'observacion' => $item->note ?? "",
                    'estado' => $reposition->status ?? "",
                    'placa' => $item->vehicle_plate ?? "",
                    'mesRol' => $item->month ?? "",
                ];
            })->filter()->values(); // Limpia nulos y reindexa

            return response()->json([
                'data' => [
                    'success' => true,
                    'status' => 200,
                    'descuentos' => $descuentos,
                ]
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'error' => 'Error al consultar la información. Intenta nuevamente más tarde.',
                'detalle' => $error->getMessage(), // opcional en desarrollo
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
