<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class StoreSalesInvoiceReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sales_invoice_id' => 'required|exists:sales_invoices,id',
            'return_method' => 'required|string',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'nullable|integer|exists:units,id',
            'items.*.color_id' => 'nullable|integer|exists:colors,id',
            'items.*.size_id' => 'nullable|integer|exists:sizes,id',
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                ...(Schema::hasTable('product_variants') ? ['exists:product_variants,id'] : []),
            ],
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.reason' => 'required|string',
        ];
    }
}

