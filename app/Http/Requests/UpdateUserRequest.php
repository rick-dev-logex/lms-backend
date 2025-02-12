<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $this->user->id,
            'role_id' => 'sometimes|required|exists:roles,id',
            'password' => 'sometimes|nullable|min:8',
            'dob' => ['nullable', 'date'],
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:permissions,id'
        ];
    }
}
