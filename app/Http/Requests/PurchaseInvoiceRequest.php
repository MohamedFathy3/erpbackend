<?php

namespace App\Http\Requests;

use App\Models\AutomotiveService;
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

            // ✅ التحقق من أن المنتج مش خدمة (زي غسيل السيارة)
            'items.*.product_id' => [
                'required',
                'exists:products,id',
                function ($attribute, $value, $fail) {
                    // نتحقق إن المنتج مش مرتبط بخدمة من نوع service
                    $isService = AutomotiveService::query()
                        ->where('product_id', $value)
                        ->where('item_type', 'service')
                        ->exists();

                    if ($isService) {
                        // نجيب اسم المنتج عشان الرسالة تكون أوضح
                        $productName = \App\Models\Product::query()
                            ->where('id', $value)
                            ->value('name');

                        $fail(
                            $productName
                                ? "المنتج \"{$productName}\" خدمة ولا يمكن إضافته إلى فاتورة مشتريات."
                                : 'لا يمكن إضافة خدمة إلى فاتورة مشتريات.'
                        );
                    }
                },
            ],

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
            'items.*.unit_id.exists' => 'الوحدة المحددة غير موجودة. اترك الوحدة فارغة لاستخدام وحدة المنتج الأساسية.',
            'items.*.product_id.required' => 'يجب اختيار منتج لكل بند.',
            'items.*.product_id.exists' => 'المنتج المحدد غير موجود.',
        ];
    }
}