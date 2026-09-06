<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevenueRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'category' => ['required', 'string', Rule::in(['sales', 'services', 'rentals', 'commissions', 'interest', 'refunds', 'other'])],
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'date' => 'required|date',
            'payment_method' => ['required', 'string', Rule::in(['cash', 'bank_transfer', 'check', 'credit_card'])],
            'reference_number' => 'nullable|string|max:100',
            'treasury_id' => 'nullable|exists:treasuries,id', // ✅ إضافة
            'currency_id' => 'nullable|exists:currencies,id', // ✅ إضافة
            'branch_id' => 'nullable|exists:branches,id', // ✅ إضافة
        ];
    }

    public function messages()
    {
        return [
            'category.required' => 'حقل الفئة مطلوب',
            'category.in' => 'الفئة غير صالحة',
            'amount.required' => 'حقل المبلغ مطلوب',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر',
            'date.required' => 'حقل التاريخ مطلوب',
            'date.date' => 'التاريخ غير صالح',
            'payment_method.required' => 'حقل طريقة الدفع مطلوب',
            'payment_method.in' => 'طريقة الدفع غير صالحة',
            'treasury_id.exists' => 'الخزينة غير موجودة',
            'currency_id.exists' => 'العملة غير موجودة',
            'branch_id.exists' => 'الفرع غير موجود',
        ];
    }
}