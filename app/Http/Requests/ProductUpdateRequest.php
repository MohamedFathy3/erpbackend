<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // فك أي JSON string
        foreach (['warehouse_stocks', 'warehouse_ids', 'removed_warehouse_ids', 'branch_ids', 'units'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }

        // تحويل [{warehouse_id, qty}] إلى {id: qty}
        $stocks = $this->input('warehouse_stocks');
        if (is_array($stocks) && $stocks !== [] && array_is_list($stocks) && is_array($stocks[0] ?? null)) {
            $map = [];
            foreach ($stocks as $row) {
                $id  = $row['warehouse_id'] ?? null;
                $qty = $row['qty'] ?? $row['stock'] ?? $row['quantity'] ?? null;
                if ($id === null || $qty === null) {
                    return;
                }
                $map[(int) $id] = $qty;
            }
            $this->merge(['warehouse_stocks' => $map]);
        }
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'name'              => 'nullable|string|max:255',
            'name_ar'           => 'nullable|string|max:255',
            'description'       => 'nullable|string',
            'description_ar'    => 'nullable|string',
            'image_url'         => 'nullable|string',
            'image'             => 'nullable|integer|exists:media,id',
            'category_id'       => 'nullable|exists:categories,id',
            'main_unit_id'      => 'nullable|exists:units,id',
            'sku' => ['nullable', 'string', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['nullable', 'string', Rule::unique('products', 'barcode')->ignore($productId)],
            'stock'             => 'nullable|numeric|min:0',
            'beginning_balance' => 'nullable|boolean',
            'reorder_level'     => 'nullable|integer|min:0',
            'price'             => 'nullable|numeric|min:0',
            'cost'              => 'nullable|numeric|min:0',
            'active'            => 'nullable|boolean',
            'has_variants'      => 'nullable|boolean',
            'valuation_method'  => 'nullable|in:fifo,lifo,weighted_average',

            'branch_ids'   => 'nullable|array',
            'branch_ids.*' => 'integer',

            'warehouse_ids'        => 'nullable|array',
            'warehouse_ids.*'      => 'exists:warehouses,id',
            'warehouse_stocks'     => 'nullable|array',
            'warehouse_stocks.*'   => 'nullable|numeric|min:0',
            'removed_warehouse_ids'   => 'nullable|array',
            'removed_warehouse_ids.*' => 'integer|min:1',

            'units' => 'sometimes|array',
            'units.*.unit_id'    => 'nullable|exists:units,id',
            'units.*.cost_price' => 'nullable|numeric|min:0',
            'units.*.sell_price' => 'nullable|numeric|min:0',
            'units.*.barcode'    => 'nullable|string',
            'units.*.colors'     => 'sometimes|array',
            'units.*.colors.*.color_id' => 'required_with:units.*.colors|exists:colors,id',
            'units.*.colors.*.stock'    => 'required_with:units.*.colors|numeric|min:0',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (isset($validated['units']) && is_array($validated['units'])) {
            foreach ($validated['units'] as &$unit) {
                if (isset($unit['colors']) && is_array($unit['colors'])) {
                    $seen = [];
                    $unique = [];
                    foreach ($unit['colors'] as $color) {
                        if (!in_array($color['color_id'], $seen)) {
                            $unique[] = $color;
                            $seen[] = $color['color_id'];
                        }
                    }
                    $unit['colors'] = $unique;
                }
            }
        }

        return $validated;
    }
}