<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        return response()->json(['data' => $roles]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $role = Role::create([
                'name' => $request->name,
                'description' => $request->description,
            ]);

            if ($request->permissions) {
                $role->permissions()->sync($request->permissions);
            }

            return response()->json([
                'data' => $role->load('permissions'),
                'message' => 'Role created successfully',
            ], 201);
        });
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $role->load('permissions')]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        return DB::transaction(function () use ($request, $role) {
            $role->update([
                'name' => $request->name,
                'description' => $request->description,
            ]);

            if ($request->permissions !== null) {
                $role->permissions()->sync($request->permissions);
            }

            return response()->json([
                'data' => $role->load('permissions'),
                'message' => 'Role updated successfully',
            ]);
        });
    }

    public function destroy(Role $role): JsonResponse
    {
        return DB::transaction(function () use ($role) {
            if ($role->users()->exists()) {
                return response()->json(['message' => 'Cannot delete role assigned to users'], 400);
            }

            $role->permissions()->detach();
            $role->delete();

            return response()->json(['message' => 'Role deleted successfully']);
        });
    }
}
