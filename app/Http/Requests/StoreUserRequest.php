<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'nullable|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'dob' => 'nullable|date',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id'
        ];
    }
}

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:users,email,{$this->user->id}",
            'password' => 'nullable|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'dob' => 'nullable|date',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ];
    }
}
