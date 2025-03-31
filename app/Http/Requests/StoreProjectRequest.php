<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'tipo' => 'required|in:empleado,proveedor',
            'description' => 'nullable|string',
        ];
    }
}

class UpdateProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'tipo' => 'sometimes|required|in:empleado,proveedor',
            'description' => 'nullable|string',
        ];
    }
}
