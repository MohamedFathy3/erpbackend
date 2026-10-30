<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'name_ar'          => 'nullable|string|max:255',
            'description'      => 'nullable|string',
            'description_ar'   => 'nullable|string',
            'sku'              => 'required|string|max:100',
            'barcode'          => 'nullable|string|max:100',
            'category_id'      => 'nullable|exists:categories,id',
            'price'            => 'nullable|numeric|min:0',
            'cost'             => 'nullable|numeric|min:0',
            'reorder_level'    => 'nullable|integer|min:0',
            'active'           => 'nullable|boolean',
            'has_variants'     => 'nullable|boolean',
            'valuation_method' => 'nullable|in:fifo,lifo,weighted_average',
            'image'            => 'nullable',
            'image_url'        => 'nullable|string',

            'warehouse_ids'       => 'nullable|array',
            'warehouse_ids.*'     => 'integer|exists:warehouses,id',
            'warehouse_stocks'    => 'nullable|array',
            'warehouse_stocks.*'  => 'numeric|min:0',

            'branch_ids'          => 'nullable|array',
            'branch_ids.*'        => 'integer|exists:branches,id',

            'units'                       => 'nullable|array',
            'units.*.unit_id'             => 'required_with:units|integer|exists:units,id',
            'units.*.cost_price'          => 'nullable|numeric|min:0',
            'units.*.sell_price'          => 'nullable|numeric|min:0',
            'units.*.barcode'             => 'nullable|string',
            'units.*.colors'              => 'nullable|array',
            'units.*.colors.*.color_id'   => 'required_with:units.*.colors|integer|exists:colors,id',
            'units.*.colors.*.stock'      => 'nullable|numeric|min:0',

            'stock' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * ✅ فك الـ JSON string لو جاي كـ string
     */
    protected function prepareForValidation(): void
    {
        $this->decodeJsonArrayField('warehouse_stocks');
        $this->decodeJsonArrayField('warehouse_ids');
        $this->decodeJsonArrayField('branch_ids');
    }

    private function decodeJsonArrayField(string $field): void
    {
        $value = $this->input($field);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $this->merge([$field => $decoded]);
            }
        }
    }
}