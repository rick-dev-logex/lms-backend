<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use App\Models\Project;
use Illuminate\Http\Request;

class ResponsibleController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('action') === 'count') {
            return response()->json(Personal::whereAll(['estado_personal', 'deleted'], ['activo', 0])->count());
        } else {
            $query = Personal::whereAll(['estado_personal', 'deleted'], ['activo', 0])->orderBy('nombre_completo', 'asc');
            // Seleccionar campos específicos si se solicitan
            if ($request->filled('fields')) {
                $query->select(explode(',', $request->fields));
            }

            if ($request->filled('proyecto')) {
                // Buscar el nombre del proyecto asociado al UUID
                $project = Project::where('id', $request->proyecto)->first();
                $projectName = $project ? $project->name : $request->proyecto;
                $query->where('proyecto', $projectName);
            }

            if ($request->filled('area')) {
                $query->where('area', $request->area);
            }

            if ($request === 'count') {
                $query->count();
            }

            return response()->json($query->get()->toArray());
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
