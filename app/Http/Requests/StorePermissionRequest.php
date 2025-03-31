<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|unique:permissions,name|max:255',
            'description' => 'nullable|string',
        ];
    }
}

class UpdatePermissionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => "required|string|unique:permissions,name,{$this->permission->id}|max:255",
            'description' => 'nullable|string',
        ];
    }
}
