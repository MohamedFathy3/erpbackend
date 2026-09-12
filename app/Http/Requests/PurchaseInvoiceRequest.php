<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class PurchaseInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'treasury_id' => 'nullable|exists:treasuries,id',

            'invoice_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if ($this->due_date && strtotime($value) > strtotime($this->due_date)) {
                        $fail('تاريخ الفاتورة يجب أن يكون قبل أو يساوي تاريخ الاستحقاق.');
                    }
                },
            ],

            'due_date' => 'nullable|date|after_or_equal:invoice_date',

            'payment_method' => 'nullable|string|in:cash,credit,check',
            'note' => 'nullable|string',

            'paid_amount' => 'nullable|numeric|min:0',

            'items' => 'required|array|min:1',

            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0|max:100',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.color_id' => 'nullable|exists:colors,id',
            // This installation stores color/size variants without a product_variants table.
            // Do not make validation query a table that is not part of the deployed schema.
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                ...(Schema::hasTable('product_variants') ? ['exists:product_variants,id'] : []),
            ],
        ];
    }

    public function messages()
    {
        return [
            'due_date.after_or_equal' => 'تاريخ الاستحقاق يجب أن يكون بعد أو يساوي تاريخ الفاتورة.',
            'payment_method.in' => 'طريقة الدفع يجب أن تكون cash, credit, أو check',
            'items.*.discount.max' => 'نسبة الخصم لا تتجاوز 100%',
            'items.*.quantity.min' => 'الكمية يجب أن تكون 1 على الأقل',
        ];
    }
}
