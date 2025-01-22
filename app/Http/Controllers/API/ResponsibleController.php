<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use Illuminate\Http\Request;

class ResponsibleController extends Controller
{
    public function index(Request $request)
    {
        $query = Personal::select('nombres', 'proyecto', 'area', 'id')
            ->where('estado_personal', 'activo'); // Filtro base

        // Filtros opcionales
        foreach (['proyecto', 'area'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter)); // Comparación exacta
            }
        }

        return response()->json($query->get());
    }





    public function store(Request $request)
    {
        $personal = Personal::create($request->all());
        return response()->json($personal, 201);
    }

    public function show($id)
    {
        $personal = Personal::findOrFail($id);
        return response()->json($personal);
    }

    public function update(Request $request, $id)
    {
        $personal = Personal::findOrFail($id);
        $personal->update($request->all());
        return response()->json($personal, 200);
    }

    public function destroy($id)
    {
        $personal = Personal::findOrFail($id);
        $personal->delete();
        return response()->json(['message' => 'Personal deleted successfully'], 204);
    }
}
