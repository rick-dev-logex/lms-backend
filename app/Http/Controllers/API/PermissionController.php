<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $permissions = Cache::remember('permissions', 3600, function () {
                return
                    Permission::select('id', 'name')
                    ->orderBy('name')
                    ->get();
            });
            // $permissions = Permission::select('id', 'name')
            //     ->orderBy('name')
            //     ->get();

            return response()->json($permissions);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:permissions,name|max:255',
                'description' => 'nullable|string'
            ]);

            return DB::transaction(function () use ($validated) {
                $permission = Permission::create($validated);

                return response()->json([
                    'message' => 'Permission created successfully',
                    'permission' => $permission
                ], 201);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Permission $permission): JsonResponse
    {
        try {
            return response()->json($permission);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Permission $permission): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:permissions,name,' . $permission->id . '|max:255',
                'description' => 'nullable|string'
            ]);

            return DB::transaction(function () use ($permission, $validated) {
                $permission->update($validated);

                return response()->json([
                    'message' => 'Permission updated successfully',
                    'permission' => $permission->fresh()
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Permission $permission): JsonResponse
    {
        try {
            return DB::transaction(function () use ($permission) {
                // Check if permission is in use
                if ($permission->users()->exists()) {
                    throw new \Exception('Cannot delete permission that is assigned to users');
                }

                $permission->delete();

                return response()->json([
                    'message' => 'Permission deleted successfully'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function assignToRole(Request $request, Permission $permission): JsonResponse
    {
        try {
            $validated = $request->validate([
                'role_id' => 'required|exists:roles,id'
            ]);

            return DB::transaction(function () use ($permission, $validated) {
                $permission->roles()->syncWithoutDetaching([$validated['role_id']]);

                return response()->json([
                    'message' => 'Permission assigned to role successfully'
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error assigning permission to role',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
