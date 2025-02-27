<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Definir roles
        $roles = [
            'registrador',
            'revisor',
            'revisor_aprobador',
            'visualizador',
            'admin',
            'developer'
        ];

        // Primero, crear todos los roles
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }

        // Definir asignaciones de permisos por rol
        $rolePermissions = [
            'registrador' => [
                'register_income',
                'view_income',
                'view_requests',
                'view_reports',
            ],
            'revisor' => [
                'view_income',
                'view_discounts',
                'view_expenses',
                'view_requests',
                'view_reports',
                'view_budget',
                'view_provisions',
            ],
            'revisor_aprobador' => [
                'view_income',
                'edit_income',
                'view_discounts',
                'manage_discounts',
                'view_expenses',
                'manage_expenses',
                'view_requests',
                'manage_requests',
                'view_reports',
                'view_budget',
                'view_provisions',
            ],
            'visualizador' => [
                'view_income',
                'view_discounts',
                'view_expenses',
                'view_requests',
                'view_reports',
                'view_budget',
                'view_provisions',
            ],
            'admin' => [], // Todos los permisos
            'developer' => [], // Todos los permisos
        ];

        // Asignar permisos a cada rol
        $allPermissions = Permission::all();

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();

            if ($role) {
                if (in_array($roleName, ['admin', 'developer'])) {
                    // Asignar todos los permisos
                    $role->permissions()->sync($allPermissions->pluck('id')->toArray());
                } else {
                    // Asignar solo los permisos específicos
                    $permissions = Permission::whereIn('name', $permissionNames)->get();
                    $role->permissions()->sync($permissions->pluck('id')->toArray());
                }
            }
        }
    }
}
