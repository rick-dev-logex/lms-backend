<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\Personal;

class ActiveProjectRule implements Rule
{
    public function passes($attribute, $value)
    {
        return Personal::where('proyecto', $value)
            ->where('estado_personal', 'activo')
            ->exists();
    }

    public function message()
    {
        return 'The selected project must have active personnel.';
    }
}
