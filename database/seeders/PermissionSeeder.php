<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Usuarios
            ['name' => 'view_users'],
            ['name' => 'edit_users'],
            ['name' => 'manage_users'],

            // Ingresos
            ['name' => 'view_income'],
            ['name' => 'edit_income'],
            ['name' => 'manage_income'],

            // Descuentos
            ['name' => 'view_discounts'],
            ['name' => 'edit_discounts'],
            ['name' => 'manage_discounts'],

            // Gastos
            ['name' => 'view_expenses'],
            ['name' => 'edit_expenses'],
            ['name' => 'manage_expenses'],

            // Solicitudes
            ['name' => 'view_requests'],
            ['name' => 'edit_requests'],
            ['name' => 'manage_requests'],

            // Reposiciones
            ['name' => 'view_repositions'],
            ['name' => 'edit_repositions'],
            ['name' => 'manage_repositions'],

            // Presupuesto
            ['name' => 'view_budget'],
            ['name' => 'edit_budget'],
            ['name' => 'manage_budget'],

            // Provisiones
            ['name' => 'view_provisions'],
            ['name' => 'edit_provisions'],
            ['name' => 'manage_provisions'],

            // Soporte
            ['name' => 'view_support'],
            ['name' => 'edit_support'],
            ['name' => 'manage_support'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
