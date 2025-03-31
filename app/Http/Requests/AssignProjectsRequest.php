<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignProjectsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'projects' => 'required|array',
            'projects.*' => 'exists:sistema_onix.onix_proyectos,id',
        ];
    }
}
