<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReposicionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_ids' => 'required|array|min:1',
            'request_ids.*' => 'exists:requests,unique_id',
            'attachment' => 'required|file|mimes:pdf,jpg,png|max:10240',
            'note' => 'nullable|string',
        ];
    }
}

class UpdateReposicionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:pending,paid,rejected,review',
            'month' => 'sometimes|string',
            'when' => 'sometimes|in:rol,liquidación,decimo_tercero,decimo_cuarto,utilidades',
            'note' => 'sometimes|string',
        ];
    }
}
