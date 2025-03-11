<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = 'L0g3X2025*';

        // Obtener IDs de roles
        $registradorRoleId = Role::where('name', 'registrador')->value('id');
        $revisorRoleId = Role::where('name', 'revisor')->value('id');
        $revisorAprobadorRoleId = Role::where('name', 'revisor_aprobador')->value('id');
        $visualizadorRoleId = Role::where('name', 'visualizador')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $developerRoleId = Role::where('name', 'developer')->value('id');

        // Lista de usuarios con sus roles por ID
        $users = [
            ['name' => 'Andres Leon', 'email' => 'andres.leon@logex.ec', 'role_id' => $revisorRoleId],
            ['name' => 'Claudia Pereira', 'email' => 'claudia.pereira@logex.ec', 'role_id' => $revisorAprobadorRoleId],
            ['name' => 'John Kenyon', 'email' => 'jk@logex.ec', 'role_id' => $adminRoleId],
            ['name' => 'Lorena Herrera', 'email' => 'lorena.herrera@logex.ec', 'role_id' => $revisorAprobadorRoleId],
            ['name' => 'Luigi Mejia', 'email' => 'luigi.mejia@logex.ec', 'role_id' => $revisorRoleId],
            ['name' => 'Luis Espinosa', 'email' => 'luis.espinosa@logex.ec', 'role_id' => $revisorAprobadorRoleId],
            ['name' => 'Mercedes Ayala', 'email' => 'mercedes.ayala@logex.ec', 'role_id' => $visualizadorRoleId],
            ['name' => 'Nicolas Iza', 'email' => 'nicolas.iza@logex.ec', 'role_id' => $adminRoleId],
            ['name' => 'Omar Rubio', 'email' => 'omar.rubio@logex.ec', 'role_id' => $visualizadorRoleId],
            ['name' => 'Guillermo Cisneros', 'email' => 'guillermo.cisneros@logex.ec', 'role_id' => $revisorAprobadorRoleId],
            ['name' => 'Oscar Oyarvide', 'email' => 'oscar.oyarvide@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Pamela Olmedo', 'email' => 'pamela.olmedo@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'RMD', 'email' => 'rmd@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Hector Rivas', 'email' => 'hector.rivas@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Cristian Fernandez', 'email' => 'cristian.fernandez@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Fernando Duchi', 'email' => 'fernando.duchi@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Ronald Maza', 'email' => 'ronald.maza@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Daysi Suasnavas', 'email' => 'daysi.suasnavas@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'David Casa', 'email' => 'david.casa@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Santiago Endara', 'email' => 'santiago.endara@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Diego Vargas', 'email' => 'diego.vargas@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Juan German', 'email' => 'juan.german@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Kevin Barzola', 'email' => 'kevin.barzola@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Marcos Yungan', 'email' => 'marcos.yungan@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Carlos Rodriguez', 'email' => 'carlos.rodriguez@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Maximo Arreaga', 'email' => 'maximo.arreaga@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Rainer Contreras', 'email' => 'rainer.contreras@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Eduardo Jurado', 'email' => 'eduardo.jurado@logex.ec', 'role_id' => $registradorRoleId],
            ['name' => 'Ricardo Estrella', 'email' => 'ricardo.estrella@logex.ec', 'role_id' => $developerRoleId],
            ['name' => 'Damian Frutos', 'email' => 'damian.frutos@logex.ec', 'role_id' => $developerRoleId],
            ['name' => 'Jonathan', 'email' => 'jonathan@logex.ec', 'role_id' => $developerRoleId],
            ['name' => 'Jonathan Visconti', 'email' => 'jonathan.visconti@logex.ec', 'role_id' => $developerRoleId],
            ['name' => 'Nicolás Iza', 'email' => 'nicolas.iza@logex.ec', 'role_id' => $adminRoleId],
        ];

        // Crear usuarios y asignar roles
        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make($defaultPassword),
                    'role_id' => $userData['role_id'],
                ]
            );

            // Si el usuario ya existía, asegurarse de actualizar el role_id
            if ($user->role_id != $userData['role_id']) {
                $user->role_id = $userData['role_id'];
                $user->save();
            }

            // Sincronizar permisos del usuario basados en su rol
            $role = Role::find($userData['role_id']);
            if ($role) {
                $permissionIds = $role->permissions->pluck('id')->toArray();
                $user->permissions()->sync($permissionIds);
            }
        }
    }
}
