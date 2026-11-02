<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['warehouse_ids', 'warehouse_stocks', 'branch_ids', 'units'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }

        $stocks = $this->input('warehouse_stocks');
        if (is_array($stocks) && array_is_list($stocks) && is_array($stocks[0] ?? null)) {
            $stockMap = [];
            $validRows = true;
            foreach ($stocks as $row) {
                $warehouseId = $row['warehouse_id'] ?? null;
                $quantity = $row['qty'] ?? $row['stock'] ?? $row['quantity'] ?? null;
                if ($warehouseId === null || $quantity === null) {
                    $validRows = false;
                    break;
                }
                $stockMap[(int) $warehouseId] = $quantity;
            }
            if ($validRows) {
                $this->merge(['warehouse_stocks' => $stockMap]);
            }
        }
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            // product
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'image_url'       => 'nullable|string',
            'category_id'     => 'nullable|exists:categories,id',
            'branch_id'       => 'nullable|integer|exists:branches,id',
            'branch_ids'      => 'nullable|array',
            'branch_ids.*'    => 'integer|exists:branches,id',
            'sku' => [
                'nullable',
                'string',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'barcode' => [
                'nullable',
                'string',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'stock'           => 'nullable|numeric|min:0',
            'beginning_balance' => 'nullable|boolean',
            'reorder_level'   => 'nullable|integer|min:0',
            'price'           => 'nullable|numeric|min:0',
            'cost'            => 'nullable|numeric|min:0',
            'product_type'      => ['nullable', Rule::in(['good', 'service', 'material', 'semi_finished', 'finished'])],
            'stock_unit'        => 'nullable|string|max:32',
            'base_unit'         => 'nullable|string|max:32',
            'conversion_factor' => 'nullable|numeric|gt:0',
            'tracking'          => ['nullable', Rule::in(['none', 'lot', 'serial'])],
            'dimensions'        => 'nullable|array',
            'dimensions.width'  => 'nullable|numeric|min:0',
            'dimensions.height' => 'nullable|numeric|min:0',
            'dimensions.depth'  => 'nullable|numeric|min:0',
            'dimensions.unit'   => 'nullable|string|max:16',

            // units
            'units' => 'sometimes|array',
            'units.*.unit_id'             => 'required|exists:units,id',
            'units.*.cost_price'          => 'required|numeric|min:0',
            'units.*.sell_price'          => 'required|numeric|min:0',
            'units.*.barcode'             => 'nullable|string',



            'warehouse_ids'   => 'nullable|array',
            'warehouse_ids.*' => 'exists:warehouses,id',
            'warehouse_stocks' => 'nullable|array',
            'warehouse_stocks.*' => 'nullable|numeric|min:0',
            // colors per unit - ✅ إزالة قواعد distinct
            'units.*.colors' => 'sometimes|array',
            'units.*.colors.*.color_id'   => 'required_with:units.*.colors|exists:colors,id',
            'units.*.colors.*.stock'      => 'required_with:units.*.colors|integer|min:0',
        ];
    }

    /**
     * بعد التحقق من الصلاحية، نقوم بتنقية البيانات من التكرار
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // تنقية الـ units من الألوان المكررة
        if (isset($validated['units']) && is_array($validated['units'])) {
            foreach ($validated['units'] as &$unit) {
                if (isset($unit['colors']) && is_array($unit['colors'])) {
                    // إزالة الألوان المكررة بناءً على color_id
                    $uniqueColors = [];
                    $seenColorIds = [];

                    foreach ($unit['colors'] as $color) {
                        if (!in_array($color['color_id'], $seenColorIds)) {
                            $uniqueColors[] = $color;
                            $seenColorIds[] = $color['color_id'];
                        }
                    }

                    $unit['colors'] = $uniqueColors;
                }
            }
        }

        return $validated;
    }
}
