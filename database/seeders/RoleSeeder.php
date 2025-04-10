<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin',
            'developer',
            'finance_manager',
            'income_registrar',
            'expense_registrar',
            'discount_registrar',
            'reposition_manager',
            'viewer',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }

        $rolePermissions = [
            'admin' => [], // Todos los permisos
            'developer' => [], // Todos los permisos
            'finance_manager' => [
                'manage_income',
                'manage_expenses',
                'manage_discounts',
                'manage_requests',
                'manage_repositions',
                'manage_budget',
                'manage_provisions',
            ],
            'income_registrar' => [
                'edit_income',
                'view_expenses',
                'view_discounts',
            ],
            'expense_registrar' => [
                'edit_expenses',
                'view_discounts',
            ],
            'discount_registrar' => [
                'edit_discounts',
                'view_expenses',
            ],
            'reposition_manager' => [
                'manage_repositions',
            ],
            'viewer' => [
                'view_income',
                'view_expenses',
                'view_discounts',
                'view_requests',
                'view_repositions',
                'view_budget',
                'view_provisions',
            ],
        ];

        $allPermissions = Permission::all();

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                if (in_array($roleName, ['admin', 'developer'])) {
                    $role->permissions()->sync($allPermissions->pluck('id')->toArray());
                } else {
                    $permissions = Permission::whereIn('name', $permissionNames)->get();
                    $role->permissions()->sync($permissions->pluck('id')->toArray());
                }
            }
        }
    }
}
