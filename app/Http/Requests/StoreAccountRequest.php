<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50|unique:accounts,account_number',
            'account_type' => ['required', Rule::in(['nomina', 'transportista'])],
            'account_status' => ['required', Rule::in(['active', 'inactive'])],
            'account_affects' => ['required', Rule::in(['discount', 'expense', 'both'])],
            'generates_income' => 'required|boolean',
        ];
    }
}

class UpdateAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'account_number' => "sometimes|string|max:50|unique:accounts,account_number,{$this->account->id}",
            'account_type' => ['sometimes', Rule::in(['nomina', 'transportista'])],
            'account_status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'account_affects' => ['sometimes', Rule::in(['discount', 'expense', 'both'])],
            'generates_income' => 'sometimes|boolean',
        ];
    }
}
