<?php

namespace App\Http\Controllers;

use App\Models\Personal;
use Illuminate\Http\Request;
use App\Http\Resources\PersonalResource;

class PersonalController extends Controller
{
    public function index()
    {
        $personal = Personal::with('cargo')->paginate(10);

        return response()->json([
            'data' => PersonalResource::collection($personal),
            'meta' => [
                'current_page' => $personal->currentPage(),
                'from' => $personal->firstItem(),
                'last_page' => $personal->lastPage(),
                'per_page' => $personal->perPage(),
                'to' => $personal->lastItem(),
                'total' => $personal->total(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        // La funcion store, como dice su nombre, guarda los datos, se ejecuta cuando recibe el método POST
        $validated = $request->validate([
            'name' => 'required|string',
            'nombres' => 'required|string',
            'apellidos' => 'required|string',
            'correo_electronico' => 'required|email',
            'estado_personal' => 'required|in:activo,inactivo',
            'sexo' => 'required|in:masculino,femenino',
            'fecha_nacimiento' => 'required|date',
            'estado_civil' => 'required|in:soltero,casado,divorciado,viudo',
            'fecha_ingreso_iess' => 'required|date',
            'estado_contractual' => 'required|string',
            'sectorial' => 'required|string',
            'fecha_contrato' => 'required|date',
            'proyecto' => 'required|string',
            'area' => 'required|string',
            'subarea' => 'required|string',
            'cargo_logex' => 'required|string',
            'hora_ingreso_laboral' => 'required',
            'hora_salida_laboral' => 'required',
        ]);

        $personal = Personal::create($validated);
        return new PersonalResource($personal);
    }

    public function show(Personal $personal)
    {
        // Esta función show se ejecuta al recibir un GET con el parámetro de búsqueda
        // Así que solo muestra ese registro. Ej. api/personal?id=1234 muestra el personal con id 1234
        return new PersonalResource($personal);
    }

    public function update(Request $request, Personal $personal)
    {
        // Se ejecuta cuando recibe un PUT o PATCH, y actualiza los campos con las validaciones que le pongas. Si una validación no se cumple, retorna un error 500

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'nombres' => 'sometimes|string',
            'apellidos' => 'sometimes|string',
            'correo_electronico' => 'sometimes|email',
            'estado_personal' => 'sometimes|in:activo,inactivo',
            'sexo' => 'sometimes|in:masculino,femenino',
            'fecha_nacimiento' => 'sometimes|date',
            'estado_civil' => 'sometimes|in:soltero,casado,divorciado,viudo',
            'fecha_ingreso_iess' => 'sometimes|date',
            'estado_contractual' => 'sometimes|string',
            'sectorial' => 'sometimes|string',
            'fecha_contrato' => 'sometimes|date',
            'proyecto' => 'sometimes|string',
            'area' => 'sometimes|string',
            'subarea' => 'sometimes|string',
            'cargo_logex' => 'sometimes|string',
            'hora_ingreso_laboral' => 'sometimes',
            'hora_salida_laboral' => 'sometimes',
        ]);

        $personal->update($validated);
        return new PersonalResource($personal);
    }

    public function destroy(Personal $personal)
    {
        // Similar al show, pero se ejecuta al recibir el método DELETE
        $personal->delete();
        return response()->noContent();
    }
}
