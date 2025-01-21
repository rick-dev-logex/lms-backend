<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PersonnelService
{
    public function validateActivePersonnel(string $name, string $project): bool
    {
        return DB::connection('sistema_onix')
            ->table('onix_personal')
            ->where('nombres', $name)
            ->where('proyecto', $project)
            ->where('estado_personal', 'activo')
            ->exists();
    }

    public function validateActiveTransport(string $name, string $project): bool
    {
        return DB::connection('tms')
            ->table('onix_vehiculos')
            ->where('name', $name)
            ->where('proyecto', $project)
            ->where('deleted', 0)
            ->exists();
    }
}
