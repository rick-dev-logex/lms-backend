<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ActivePersonnelProjectRule implements Rule
{
    private $type;
    private $project;

    public function __construct($type, $project)
    {
        $this->type = $type;
        $this->project = $project;
    }

    public function passes($attribute, $value)
    {
        if ($this->type === 'nomina') {
            return DB::connection('onix')
                ->table('onix_personal')
                ->where('nombres', $value)
                ->where('proyecto', $this->project)
                ->where('estado_personal', 'activo')
                ->exists();
        }

        if ($this->type === 'transportista') {
            return DB::connection('onix')
                ->table('onix_vehiculos')
                ->where('name', $value)
                ->where('proyecto', $this->project)
                ->where('deleted', 0)
                ->exists();
        }

        return false;
    }

    public function message()
    {
        return 'The selected person/transport must be active and belong to the specified project.';
    }
}

class ValidProjectAccountRule implements Rule
{
    private $type;

    public function __construct($type)
    {
        $this->type = $type;
    }

    public function passes($attribute, $value)
    {
        if ($this->type === 'nomina') {
            return DB::connection('onix')
                ->table('onix_proyectos')
                ->where('id', $value)
                ->where('tipo', 'empleado')
                ->exists();
        }

        if ($this->type === 'transportista') {
            return DB::connection('onix')
                ->table('onix_proyectos')
                ->where('id', $value)
                ->where('tipo', 'proveedor')
                ->exists();
        }

        return false;
    }

    public function message()
    {
        return 'Invalid project type for the selected request type.';
    }
}
