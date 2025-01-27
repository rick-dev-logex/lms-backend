<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        // \Log::info('PermissionController@index called');
        $permissions = Permission::select('id', 'name')->get();
        return response()->json($permissions);
    }

    public function store(Request $request): JsonResponse
    {
        $permission = Permission::create($request->all());
        return response()->json($permission, 201);
    }

    public function show(Permission $permission): JsonResponse
    {
        return response()->json($permission);
    }

    public function update(Request $request, Permission $permission): JsonResponse
    {
        $permission->update($request->all());
        return response()->json($permission);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();
        return response()->json(['message' => 'Permission deleted successfully']);
    }
}
