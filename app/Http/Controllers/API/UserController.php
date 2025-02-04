<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

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

            return response()->json($results);
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
                $userData = [
                    'name' => $request->name,
                    'email' => $request->email,
                ];

                if ($request->filled('password')) {
                    $userData['password'] = Hash::make($request->password);
                }

                if ($request->filled('role_id')) {
                    $userData['role_id'] = $request->role_id;
                }

                $user->update($userData);

                if ($request->has('permissions')) {
                    $user->permissions()->sync($request->permissions);
                }

                return response()->json([
                    'message' => 'User updated successfully',
                    'user' => $user->fresh(['role', 'permissions'])
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(User $user): JsonResponse
    {
        try {
            $user->permissions()->detach();
            $user->delete();

            return response()->json([
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id'
            ]);

            return DB::transaction(function () use ($request, $user) {
                $user->permissions()->sync($request->permissions);

                return response()->json([
                    'message' => 'Permissions updated successfully',
                    'user' => $user->fresh(['role', 'permissions'])
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
