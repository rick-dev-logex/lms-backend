<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Project::where('deleted', '0')->where('activo', '1');

        if ($request->filled('projects')) {
            $projectIds = explode(',', $request->input('projects'));
            $query->whereIn('id', $projectIds);
        }

        if ($request->filled('proyecto')) {
            $query->where('proyecto', $request->input('proyecto'));
        }

        if ($request->filled('user_id')) {
            $query->whereHas('users', function ($q) use ($request) {
                $q->where('users.id', $request->input('user_id'));
            });
        }

        $projects = $query->select('id', 'name', 'description')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($projects);
    }

    public function show(int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

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

    /**
     * Obtener usuarios asignados al proyecto
     */
    public function getProjectUsers(int $id): JsonResponse
    {
        try {
            $project = Project::findOrFail($id);

            $users = $project->users()
                ->select('users.id', 'users.name', 'users.email')
                ->get();

            return response()->json($users);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching project users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar usuarios al proyecto
     */
    public function assignUsers(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::findOrFail($id);

            // Verificar que el proyecto esté activo
            if ($project->activo != '1' || $project->deleted != '0') {
                return response()->json([
                    'message' => 'Cannot assign users to inactive project'
                ], 400);
            }

            $validated = $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'required|integer|exists:users,id'
            ]);

            $project->users()->sync($validated['user_ids']);

            return response()->json([
                'message' => 'Users assigned successfully',
                'users' => $project->users()->select('users.id', 'users.name', 'users.email')->get()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error assigning users',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
