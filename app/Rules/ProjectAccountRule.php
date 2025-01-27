<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectAccountRule implements Rule
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function passes($attribute, $value): bool
    {
        $query = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->where('id', $value);

        // Para descuentos, validamos el tipo
        // if ($this->request->type === 'discount') {
        //     $tipo = $this->request->personnel_type === 'nomina' ? 'empleado' : 'proveedor';
        //     $query->where('tipo', $tipo);
        // }

        return $query->exists();
    }

    public function message(): string
    {
        return 'Invalid project for the selected type.';
    }
}
