<?php

namespace App\Http\Controllers;

use App\Models\Personal;
use Illuminate\Http\Request;
use App\Http\Resources\PersonalResource;

class PersonalController extends Controller
{
    public function index()
    {
        return PersonalResource::collection(Personal::paginate(10));
    }

    public function store(Request $request)
    {
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
        return new PersonalResource($personal);
    }

    public function update(Request $request, Personal $personal)
    {
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
        $personal->delete();
        return response()->noContent();
    }
}
