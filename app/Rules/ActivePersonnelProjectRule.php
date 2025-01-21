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
            return DB::connection('sistema_onix')
                ->table('onix_personal')
                ->where('nombres', $value)
                ->where('proyecto', $this->project)
                ->where('estado_personal', 'activo')
                ->exists();
        }

        if ($this->type === 'transportista') {
            return DB::connection('tms')
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
        // For nomina type, check lms_backend projects table
        if ($this->type === 'nomina') {
            return DB::connection('lms_backend')
                ->table('projects')
                ->where('id', $value)
                ->where('tipo', 'empleado')
                ->exists();
        }

        // For transportista type, check for provider type
        if ($this->type === 'transportista') {
            return DB::connection('lms_backend')
                ->table('projects')
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
