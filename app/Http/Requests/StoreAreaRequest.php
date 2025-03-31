<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAreaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255', // Asumo que tiene un campo 'name'
        ];
    }
}

class UpdateAreaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
        ];
    }
}
