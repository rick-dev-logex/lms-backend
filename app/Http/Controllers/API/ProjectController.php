<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = Project::select('id', 'name')
            ->where('activo', '1')
            ->when($request->has('proyecto'), function ($query) use ($request) {
                return $query->where('proyecto', $request->proyecto);
            })
            ->get();

        return response()->json($projects);
    }

    public function show(int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        return response()->json($project);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tipo' => 'required|string|in:empleado,proveedor',
            // Agrega aquí otras validaciones según tu modelo
        ]);

        $project = Project::create($validated);
        return response()->json($project, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'tipo' => 'sometimes|required|string|in:empleado,proveedor',
            // Agrega aquí otras validaciones según tu modelo
        ]);

        $project->update($validated);
        return response()->json($project);
    }

    public function destroy(int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully',
            'id' => $id
        ]);
    }
}
