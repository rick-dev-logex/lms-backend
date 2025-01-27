<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use Illuminate\Http\Request;

class ResponsibleController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('action') === 'count') {
            return response()->json(Personal::where('estado_personal', 'activo')->count());
        } else {
            $query = Personal::where('estado_personal', 'activo');

            // Seleccionar campos específicos si se solicitan
            if ($request->filled('fields')) {
                $query->select(explode(',', $request->fields));
            }

            if ($request->filled('proyecto')) {
                $query->where('proyecto', $request->proyecto);
            }

            if ($request->filled('area')) {
                $query->where('area', $request->area);
            }

            if ($request === 'count') {
                $query->count();
            }

            return response()->json($query->get());
        }
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
