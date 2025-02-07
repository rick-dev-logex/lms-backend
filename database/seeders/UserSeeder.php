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
        $devRole = Role::where('name', 'developer')->first();
        $adminRole = Role::where('name', 'admin')->first();

        $userRole = Role::where('name', 'user')->first();

        $allPermissions = Permission::all();
        $basicPermissions = Permission::whereIn('name', [
            'view_discounts',
            'view_expenses',
            'view_requests',
            'view_reports',
            'view_budget',
            'manage_support'
        ])->get();

        $developer = User::firstOrCreate(
            ['email' => 'ricardo.estrella@logex.ec'],
            [
                'name' => 'Ricardo Estrella',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $devRole->id,
            ]
        );

        $jk = User::firstOrCreate(
            ['email' => 'jk@logex.ec'],
            [
                'name' => 'John Kenyon',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $adminRole->id,
            ]
        );

        $cp = User::firstOrCreate(
            ['email' => 'claudia.pereira@logex.ec'],
            [
                'name' => 'Claudia Pereira',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $adminRole->id,
            ]
        );

        $or = User::firstOrCreate(
            ['email' => 'omar.rubio@logex.ec'],
            [
                'name' => 'Omar Rubio',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $adminRole->id,
            ]
        );

        $df = User::firstOrCreate(
            ['email' => 'damian.frutos@logex.ec'],
            [
                'name' => 'Damián Frutos',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $developer->id,
            ]
        );

        // Asignar permisos
        $developer->permissions()->sync($allPermissions->pluck('id'));
        $df->permissions()->sync($basicPermissions->pluck('id'));
        $jk->permissions()->sync($allPermissions->pluck('id'));
        $cp->permissions()->sync($allPermissions->pluck('id'));
        $or->permissions()->sync($allPermissions->pluck('id'));
    }
}
