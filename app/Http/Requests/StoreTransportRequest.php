<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'account_id' => 'nullable|exists:accounts,id',
            'deleted' => 'sometimes|in:0,1',
        ];
    }
}

class UpdateTransportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'account_id' => 'sometimes|nullable|exists:accounts,id',
            'deleted' => 'sometimes|in:0,1',
        ];
    }
}
