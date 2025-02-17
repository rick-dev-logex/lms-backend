<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = User::with(['role', 'permissions']);

            if ($request->input('action') === 'count') {
                return response()->json($query->count());
            }

            // Filtros
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('role_id')) {
                $query->where('role_id', $request->input('role_id'));
            }

            // Ordenamiento
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('order', 'desc');
            $query->orderBy($sortField, $sortOrder);

            // Paginación
            $perPage = $request->input('per_page', 10);

            $results = $perPage === 'all' ? $query->get() : $query->paginate($perPage);

            // Agregar los códigos de proyecto a cada usuario
            $data = $perPage === 'all' ? $results : $results->getCollection();
            $data->transform(function ($user) {
                // Crear un array con los datos del usuario
                $userData = $user->toArray();
                // Agregar los códigos de proyecto como un campo adicional
                $userData['projects'] = $user->project_details;
                return $userData;
            });

            if ($perPage !== 'all') {
                $results->setCollection($data);
                return response()->json($results);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $userData = [
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password ?? 'L0g3X2025*'),
                    'role_id' => $request->role_id ?? Role::where('name', 'user')->value('id'),
                ];

                $user = User::create($userData);

                if ($request->has('permissions')) {
                    $user->permissions()->attach($request->permissions);
                }

                return response()->json([
                    'message' => 'User created successfully',
                    'user' => $user->load(['role', 'permissions'])
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(User $user): JsonResponse
    {
        try {
            return response()->json($user->load(['role', 'permissions']));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $user) {
                // Validar datos básicos
                $validated = $request->validated();

                // Preparar datos de actualización
                $userData = [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                ];

                // Actualizar role_id si se proporciona
                if (isset($validated['role_id'])) {
                    $userData['role_id'] = $validated['role_id'];
                }

                // Actualizar password si se proporciona
                if (!empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }

                // Actualizar usuario
                $user->update($userData);

                // Sincronizar permisos si se proporcionan
                if (isset($validated['permissions'])) {
                    // Asegurarse de que los permisos sean un array y convertir a enteros
                    $permissions = array_map('intval', (array) $validated['permissions']);
                    $user->permissions()->sync($permissions);
                }

                // Cargar relaciones y devolver respuesta
                $user->load(['role', 'permissions']);

                return response()->json([
                    'message' => 'User updated successfully',
                    'user' => $user
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        try {
            // Validación específica para permisos
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'required|integer|exists:permissions,id'
            ]);

            return DB::transaction(function () use ($validated, $user) {
                // Convertir todos los IDs a enteros
                $permissions = array_map('intval', $validated['permissions']);

                // Sincronizar permisos
                $user->permissions()->sync($permissions);

                // Recargar el modelo con sus relaciones
                $user->load(['role', 'permissions']);

                return response()->json([
                    'message' => 'Permissions updated successfully',
                    'user' => $user
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error updating permissions: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(User $user): JsonResponse
    {
        try {
            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            // Verificar si es el usuario actual usando request()->user()
            if ($user->id === request()->user()?->id) {
                return response()->json([
                    'message' => 'Cannot delete your own account'
                ], 403);
            }

            DB::beginTransaction();
            try {
                $user->permissions()->detach();
                $user->delete();
                DB::commit();

                return response()->json([
                    'message' => 'User deleted successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error deleting user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener proyectos de un usuario específico
     */
    public function getUserProjects(User $user): JsonResponse
    {
        try {
            return response()->json([
                'projects' => $user->project_details,
                'project_codes' => $user->project_codes
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user projects: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching user projects',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar proyectos a un usuario
     */
    public function assignProjects(Request $request, User $user): JsonResponse
    {
        try {

            // Obtener los project_ids del request
            $projectIds = $request->input('projectIds', []);

            // Verificar si es un array
            if (!is_array($projectIds)) {
                return response()->json([
                    'message' => 'Error assigning projects',
                    'error' => 'projectIds must be an array',
                    'received' => $projectIds
                ], 400);
            }

            // Validar que los proyectos existen en sistema_onix
            $existingProjects = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $projectIds)
                ->where('deleted', 0)
                ->where('activo', 1)
                ->pluck('id')
                ->toArray();

            if (count($existingProjects) !== count($projectIds)) {
                return response()->json([
                    'message' => 'Error assigning projects',
                    'error' => 'Some projects do not exist or are inactive'
                ], 400);
            }

            // Realizar la sincronización en la base de datos LMS
            DB::connection('lms_backend')->transaction(function () use ($projectIds, $user) {
                $user->projects()->sync($projectIds);
            });



            return response()->json([
                'message' => 'Projects assigned successfully',
                'projects' => $user->project_details,
                'project_codes' => $user->project_codes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error assigning projects',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Los proyectos asignados al usuario
     */
    public function projects()
    {
        return $this->belongsToMany(Project::class, 'user_project')
            ->withTimestamps();
    }

    /**
     * Obtener los códigos de proyecto como string
     */
    public function getProjectCodesAttribute(): string
    {
        return $this->projects()
            ->active()
            ->pluck('proyecto')
            ->join(', ');
    }

    /**
     * Obtener información completa de proyectos
     */
    public function getProjectDetailsAttribute(): array
    {
        return $this->projects()
            ->active()
            ->select('id', 'name as code', 'description as name')
            ->get()
            ->toArray();
    }
}
