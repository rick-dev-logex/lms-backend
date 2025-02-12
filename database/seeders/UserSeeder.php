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
        $adminRole = Role::where('name', 'admin')->first();
        $auditorRole = Role::where('name', 'auditor')->first();
        $custodioRole = Role::where('name', 'custodio')->first();
        $devRole = Role::where('name', 'developer')->first();
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

        $custodioPermissions = Permission::whereIn('name', [
            'view_discounts',
            'view_expenses',
            'view_requests',
            'view_reports',
            'manage_support',
            'manage_discounts',
            'manage_expenses',
            'manage_requests',
        ])->get();

        $auditorPermissions = Permission::whereIn('name', [
            'view_discounts',
            'view_expenses',
            'view_requests',
            'view_reports',
            'manage_support',
            'manage_discounts',
            'manage_expenses',
            'manage_requests',
        ])->get();

        $omarPermissions = Permission::whereIn('name', [
            'manage_provisions',
            'view_provisions',
            'view_discounts',
            'view_expenses',
        ]);

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
                'role_id' => $devRole->id,
            ]
        );

        $cp = User::firstOrCreate(
            ['email' => 'claudia.pereira@logex.ec'],
            [
                'name' => 'Claudia Pereira',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $custodioRole->id,
            ]
        );

        $or = User::firstOrCreate(
            ['email' => 'omar.rubio@logex.ec'],
            [
                'name' => 'Omar Rubio',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $adminRole->id, //Provisiones, descuentos y gastos
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

        $jv = User::firstOrCreate(
            ['email' => 'jonathan.visconti@logex.ec'],
            [
                'name' => 'Jonathan Visconti',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $developer->id,
            ]
        );

        $ni = User::firstOrCreate(
            ['email' => 'nicolas.iza@logex.ec'],
            [
                'name' => 'Nicolás Iza',
                'password' => Hash::make('L0g3X2025*'),
                'role_id' => $developer->id,
            ]
        );

        // Asignar permisos
        $developer->permissions()->sync($allPermissions->pluck('id'));
        $custodioRole->permissions()->sync($custodioPermissions->pluck('id'));
        $or->permissions()->sync($omarPermissions->pluck('id'));

        $df->permissions()->sync($allPermissions->pluck('id'));
        $jv->permissions()->sync($allPermissions->pluck('id'));
        $jk->permissions()->sync($allPermissions->pluck('id'));
        $cp->permissions()->sync($allPermissions->pluck('id'));
        $ni->permissions()->sync($allPermissions->pluck('id'));
    }
}
