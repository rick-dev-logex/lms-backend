<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\AssignProjectsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with(['role', 'permissions', 'assignedProjects'])
            ->orderBy('name', 'asc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                    ] : null,
                    'permissions' => $user->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                        ];
                    })->all(),
                    'projects' => $user->assignedProjects ? $user->assignedProjects->projects : [],
                ];
            });

        Log::debug('Users fetched', ['users' => $users->toArray()]);

        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password ?? 'L0g3X2025*'),
                'role_id' => $request->role_id,
            ]);

            if ($request->permissions) {
                $user->permissions()->sync($request->permissions);
            }

            return response()->json([
                'data' => $user->load(['role', 'permissions']),
                'message' => 'User created successfully',
            ], 201);
        });
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['role', 'permissions', 'assignedProjects']);
        $userData = $user->toArray();
        $userData['projects'] = $user->assignedProjects->pluck('projects')->flatten()->all();

        return response()->json(['data' => $userData]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'role_id' => $request->role_id,
                ...($request->password ? ['password' => Hash::make($request->password)] : []),
            ]);

            if ($request->permissions !== null) {
                $user->permissions()->sync($request->permissions);
            }

            return response()->json([
                'data' => $user->load(['role', 'permissions']),
                'message' => 'User updated successfully',
            ]);
        });
    }

    public function destroy(User $user): JsonResponse
    {
        return DB::transaction(function () use ($user) {
            if ($user->id === auth()->user()->id) {
                return response()->json(['message' => 'Cannot delete your own account'], 403);
            }

            $user->permissions()->detach();
            $user->delete();

            return response()->json(['message' => 'User deleted successfully']);
        });
    }

    public function assignProjects(AssignProjectsRequest $request, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $user) {
            // Eliminar asignaciones existentes
            $user->assignedProjects()->delete();
            // Crear una nueva asignación
            $user->assignedProjects()->create([
                'projects' => $request->projects,
            ]);

            return response()->json([
                'data' => $user->load(['role', 'permissions', 'assignedProjects']),
                'message' => 'Projects assigned successfully',
            ]);
        });
    }
}
