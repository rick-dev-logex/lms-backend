<?php

namespace App\Rules;

use App\Services\PersonnelService;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivePersonnelRule implements Rule
{
    private $request;
    private $personnelService;

    public function __construct(Request $request, PersonnelService $personnelService)
    {
        $this->request = $request;
        $this->personnelService = $personnelService;
    }

    public function passes($attribute, $value): bool
    {
        // Si es un gasto, no validamos estado
        if ($this->request->type === 'expense') {
            return true;
        }

        // Para descuentos, validamos según el tipo de personal
        if ($this->request->personnel_type === 'transportista') {
            return $this->personnelService->validateActiveTransport($value, $this->request->project);
        }

        return $this->personnelService->validateActivePersonnel($value, $this->request->project);
    }

    public function message(): string
    {
        return 'The selected person must be active for discounts.';
    }
}
