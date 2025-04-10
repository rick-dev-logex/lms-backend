<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    // UpdateUserRequest.php

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255'], // validamos formato pero no unicidad aquí
            'role_id' => 'sometimes|required|integer|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ];
    }

    public function withValidator($validator)
    {
        $validator->sometimes('email', [
            Rule::unique('users', 'email')->ignore($this->route('user')->id)
        ], function ($input) {
            $currentEmail = $this->route('user')->email ?? null;
            return isset($input->email) && $input->email !== $currentEmail;
        });
    }
}
