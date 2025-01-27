<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $query = Role::select('id', 'name');

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $role = Role::create($request->all());
        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role)
    {
        $role->update($request->all());
        return response()->json($role);
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return response()->json(['message' => 'Role deleted successfully']);
    }

    public function show(Role $role)
    {
        return response()->json($role);
    }

    public function permissions(Role $role)
    {
        $usersWithRole = User::where('role_id', $role->id)
            ->with('permissions')
            ->first();

        return response()->json([
            'permissions' => $usersWithRole ? $usersWithRole->permissions : []
        ]);
    }
}
