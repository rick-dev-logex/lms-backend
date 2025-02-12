<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Permisos administrativos
            ['name' => 'manage_users'],
            ['name' => 'view_users'],
            ['name' => 'create_users'],
            ['name' => 'edit_users'],
            ['name' => 'delete_users'],

            // Permisos de ingresos
            ['name' => 'register_income'],
            ['name' => 'view_income'],
            ['name' => 'edit_income'],

            // Permisos de registros
            ['name' => 'view_discounts'],
            ['name' => 'manage_discounts'],
            ['name' => 'view_expenses'],
            ['name' => 'manage_expenses'],

            // Permisos de gestión
            ['name' => 'view_requests'],
            ['name' => 'manage_requests'],
            ['name' => 'view_reports'],
            ['name' => 'manage_reports'],

            // Permisos especiales
            ['name' => 'manage_special_income'],
            ['name' => 'view_budget'],
            ['name' => 'manage_budget'],
            ['name' => 'view_provisions'],
            ['name' => 'manage_provisions'],
            ['name' => 'manage_support'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
