<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Role::select('id', 'name');

            if ($request->filled('id')) {
                $query->where('id', $request->input('id'));
            }

            $roles = $query->orderBy('name')->get();

            return response()->json($roles);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:roles,name|max:255',
                'description' => 'nullable|string',
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,id'
            ]);

            return DB::transaction(function () use ($validated) {
                $role = Role::create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                ]);

                if (!empty($validated['permissions'])) {
                    $role->permissions()->sync($validated['permissions']);
                }

                return response()->json([
                    'message' => 'Role created successfully',
                    'role' => $role->load('permissions')
                ], 201);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Role $role): JsonResponse
    {
        try {
            return response()->json($role->load('permissions'));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:roles,name,' . $role->id . '|max:255',
                'description' => 'nullable|string',
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,id'
            ]);

            return DB::transaction(function () use ($role, $validated) {
                $role->update([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                ]);

                if (isset($validated['permissions'])) {
                    $role->permissions()->sync($validated['permissions']);
                }

                return response()->json([
                    'message' => 'Role updated successfully',
                    'role' => $role->fresh(['permissions'])
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Role $role): JsonResponse
    {
        try {
            return DB::transaction(function () use ($role) {
                // Check if role is in use
                if ($role->users()->exists()) {
                    throw new \Exception('Cannot delete role that is assigned to users');
                }

                $role->permissions()->detach();
                $role->delete();

                return response()->json([
                    'message' => 'Role deleted successfully'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function permissions(Role $role): JsonResponse
    {
        try {
            return response()->json([
                'permissions' => $role->permissions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching role permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id'
            ]);

            return DB::transaction(function () use ($role, $validated) {
                $role->permissions()->sync($validated['permissions']);

                // Actualizar permisos de usuarios con este rol
                $users = User::where('role_id', $role->id)->get();
                foreach ($users as $user) {
                    $user->permissions()->sync($validated['permissions']);
                }

                return response()->json([
                    'message' => 'Role permissions updated successfully',
                    'role' => $role->fresh(['permissions'])
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating role permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
