<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class StoreReturnRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'purchase_invoices_id' => 'required|exists:purchase_invoices,id',
            'reason' => 'nullable|string',
            'return_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'nullable|exists:units,id',
            'items.*.color_id' => 'nullable|exists:colors,id',
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                ...(Schema::hasTable('product_variants') ? ['exists:product_variants,id'] : []),
            ],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ];
    }

    public function messages()
    {
        return [
            'purchase_invoices_id.required' => 'رقم الفاتورة مطلوب',
            'purchase_invoices_id.exists' => 'الفاتورة غير موجودة',
            'items.required' => 'يجب إضافة منتج واحد على الأقل',
            'items.*.quantity.min' => 'الكمية يجب أن تكون 1 على الأقل',
            'items.*.unit_price.min' => 'سعر الوحدة يجب أن يكون أكبر من 0',
        ];
    }
}
