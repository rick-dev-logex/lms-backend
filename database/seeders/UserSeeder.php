<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Crear usuario desarrollador
        $devRole = Role::where('name', 'developer')->first();
        $allPermissions = Permission::all();

        $developer = User::firstOrCreate(
            ['email' => 'ricardo.estrella@logex.ec'],
            [
                'name' => 'Ricardo Estrella',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $devRole->id,
            ]
        );

        // Asignar todos los permisos al desarrollador
        $developer->permissions()->sync($allPermissions->pluck('id'));

        // Crear usuario administrador por defecto si es necesario
        $adminRole = Role::where('name', 'admin')->first();
        $admin = User::firstOrCreate(
            ['email' => 'jk@logex.ec'],
            [
                'name' => 'John Kenyon',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $adminRole->id,
            ]
        );

        // Asignar todos los permisos al administrador
        $admin->permissions()->sync($allPermissions->pluck('id'));

        // Crear usuario regular por defecto
        $userRole = Role::where('name', 'user')->first();
        $basicPermissions = Permission::whereIn('name', [
            'view_discounts',
            'view_expenses',
            'view_requests',
            'view_reports',
            'view_budget',
            'manage_support'
        ])->get();

        $user = User::firstOrCreate(
            ['email' => 'damian.frutos@logex.ec'],
            [
                'name' => 'Damián Frutos',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $developer->id,
            ]
        );

        // Asignar permisos básicos al usuario regular
        $user->permissions()->sync($basicPermissions->pluck('id'));
    }
}
