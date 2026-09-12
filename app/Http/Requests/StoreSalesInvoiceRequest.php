<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'treasury_id' => 'required|exists:treasuries,id',
            'sales_representative_id' => 'required|exists:sales_representatives,id',
            'branch_id' => 'required|exists:branches,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'currency_id' => 'required|exists:currencies,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'payment_method' => 'required|in:cash,card,check',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'note' => 'nullable|string',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.product_unit_id' => 'nullable|integer',
            'items.*.color_id' => 'nullable|exists:colors,id',
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'تاريخ الاستحقاق يجب أن يكون بعد أو يساوي تاريخ الفاتورة.',
        ];
    }
}
