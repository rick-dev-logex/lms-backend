<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'type' => 'required|in:expense,discount',
            'personnel_type' => 'required|in:nomina,transportista',
            'request_date' => 'required|date',
            'invoice_number' => 'required|numeric',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'project' => 'required|string',
            'note' => 'required|string',
        ];

        if ($this->personnel_type === 'nomina') {
            $rules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,id';
        } else {
            $rules['transport_id'] = 'required|exists:sistema_onix.onix_vehiculos,id';
        }

        return $rules;
    }
}

class UpdateRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'status' => 'sometimes|in:pending,paid,rejected,review,in_reposition',
            'note' => 'sometimes|string',
            'project' => 'sometimes|string',
        ];

        if ($this->personnel_type === 'nomina') {
            $rules['responsible_id'] = 'sometimes|exists:sistema_onix.onix_personal,id';
        } else {
            $rules['transport_id'] = 'sometimes|exists:sistema_onix.onix_vehiculos,id';
        }

        return $rules;
    }
}

class ImportRequestsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'context' => 'required|in:discounts,expenses',
        ];
    }
}

class UploadDiscountsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls',
            'data' => 'required|json',
        ];
    }
}
