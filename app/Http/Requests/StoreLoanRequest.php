<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'type' => 'required|in:nomina,proveedor',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'project' => 'required|string',
            'invoice_number' => 'required|string',
            'installments' => 'required|integer|min:1|max:36',
            'installment_dates' => "required|array|size:{$this->installments}",
            'installment_dates.*' => 'required|date_format:Y-m',
            'note' => 'required|string',
            'attachment' => 'required|file|mimes:pdf,jpg,png|max:10240',
        ];

        if ($this->type === 'nomina') {
            $rules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,id';
        } else {
            $rules['vehicle_id'] = 'required|exists:sistema_onix.onix_vehiculos,id';
        }

        return $rules;
    }
}

class UpdateLoanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:pending,paid,rejected,review',
            'note' => 'sometimes|string',
            'installment_dates' => "sometimes|array|size:{$this->loan->installments}",
            'installment_dates.*' => 'required_with:installment_dates|date_format:Y-m',
        ];
    }
}
