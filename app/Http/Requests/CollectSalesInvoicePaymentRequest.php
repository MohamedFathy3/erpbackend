<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectSalesInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,bank_transfer,bank'],
            'treasury_id' => ['nullable', 'exists:treasuries,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('payment_method') === 'cash' && !$this->filled('treasury_id')) {
                $validator->errors()->add('treasury_id', 'الخزينة مطلوبة للتحصيل النقدي.');
            }
            if (in_array($this->input('payment_method'), ['bank', 'bank_transfer'], true) && !$this->filled('bank_id')) {
                $validator->errors()->add('bank_id', 'البنك مطلوب للتحويل البنكي.');
            }
        });
    }
}
