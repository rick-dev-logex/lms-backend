<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use App\Models\UserAssignedProjects;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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
            $query = User::with(['role', 'permissions', 'assignedProjects'])->orderBy('name', 'asc');

            if ($request->input('action') === 'count') {
                return response()->json(User::count());
            }

            // Obtener todos los usuarios (sin paginación)
            $users = $query->get();

            // Agregar los códigos de proyecto a cada usuario
            $data = $users->map(function ($user) {
                $userData = $user->toArray();
                $userData['projects'] = $user->project_details;
                return $userData;
            })->values()->all();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Los demás métodos permanecen sin cambios
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
        Log::info($user->load([
            'role',
            'permissions',
        ]));
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
                $validated = $request->validated();
                $userData = [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                ];
                if (isset($validated['role_id'])) {
                    $userData['role_id'] = (int) $validated['role_id']; // Asegurar que sea entero
                    Log::info("Actualizando role_id a: " . $userData['role_id']);
                } else {
                    Log::info("No se proporcionó role_id en la solicitud");
                }
                if (!empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                $user->update($userData);
                if (isset($validated['permissions'])) {
                    $permissions = array_map('intval', (array) $validated['permissions']);
                    $user->permissions()->sync($permissions);
                }
                $user->load(['role', 'permissions']);
                return response()->json([
                    'message' => 'User updated successfully',
                    'user' => $user
                ]);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error updating user: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function patch(Request $request, User $user): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $user) {
                $validated = $request->validate([
                    'dob' => 'nullable|date',
                    'phone' => 'nullable|string|max:12',
                    'password' => 'nullable|string|min:8',
                ]);
                $userData = [];
                if (isset($validated['dob'])) {
                    $userData['dob'] = $validated['dob'];
                }
                if (isset($validated['phone'])) {
                    $userData['phone'] = $validated['phone'];
                }
                if (!empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                if (!empty($userData)) {
                    $user->update($userData);
                }
                $user->load(['role']);
                return response()->json([
                    'message' => 'User profile updated successfully',
                    'user' => $user
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error updating user profile: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating user profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'required|integer|exists:permissions,id'
            ]);
            return DB::transaction(function () use ($validated, $user) {
                $permissions = array_map('intval', $validated['permissions']);
                $user->permissions()->sync($permissions);
                $user->load(['role', 'permissions']);
                return response()->json([
                    'message' => 'Permissions updated successfully',
                    'user' => $user
                ]);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error updating permissions: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
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

    public function assignProjects(Request $request, User $user)
    {
        $data = $request->validate([
            'projectIds' => 'required|array',
            'projectIds.*' => 'string'
        ]);

        try {
            UserAssignedProjects::updateOrCreate(
                ['user_id' => $user->id],
                ['projects' => $data['projectIds']]
            );
            return response()->json([
                'message' => 'Projects assigned successfully',
                'assigned_projects' => $data['projectIds']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error assigning projects',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getProjectCodesAttribute(): string
    {
        return $this->projects()
            ->active()
            ->pluck('proyecto')
            ->join(', ');
    }

    public function getProjectDetailsAttribute(): array
    {
        return $this->projects()
            ->active()
            ->select('id', 'name as code', 'description as name')
            ->get()
            ->toArray();
    }

    public function me()
    {
        return $this->user();
    }
}
