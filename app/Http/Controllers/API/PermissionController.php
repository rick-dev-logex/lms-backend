<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get();
        return response()->json(['data' => $permissions]);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = Permission::create($request->validated());
        return response()->json([
            'data' => $permission,
            'message' => 'Permission created successfully',
        ], 201);
    }

    public function show(Permission $permission): JsonResponse
    {
        return response()->json(['data' => $permission]);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission->update($request->validated());
        return response()->json([
            'data' => $permission,
            'message' => 'Permission updated successfully',
        ]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        return DB::transaction(function () use ($permission) {
            if ($permission->users()->exists() || $permission->roles()->exists()) {
                return response()->json(['message' => 'Cannot delete permission in use'], 400);
            }

            $permission->delete();
            return response()->json(['message' => 'Permission deleted successfully']);
        });
    }
}
