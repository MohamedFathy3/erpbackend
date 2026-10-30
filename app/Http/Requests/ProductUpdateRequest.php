<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'sku' => 'sometimes|required|string|max:100',
            'barcode' => 'sometimes|nullable|string|max:100',

            'category_id' => 'sometimes|nullable|exists:categories,id',

            'price' => 'sometimes|nullable|numeric|min:0',
            'cost' => 'sometimes|nullable|numeric|min:0',
            'stock' => 'sometimes|nullable|numeric|min:0',

            'reorder_level' => 'sometimes|nullable|integer|min:0',
            'active' => 'sometimes|nullable|boolean',
            'has_variants' => 'sometimes|nullable|boolean',

            'valuation_method' => 'sometimes|nullable|in:fifo,lifo,weighted_average',

            'image' => 'sometimes|nullable',
            'image_url' => 'sometimes|nullable|string',

            // Warehouses
            'warehouse_ids' => 'sometimes|array',
            'warehouse_ids.*' => 'integer|distinct|min:1|exists:warehouses,id',

            'warehouse_stocks' => 'sometimes|array',
            'warehouse_stocks.*' => 'required|numeric|min:0',

            'sync_warehouses' => 'sometimes|boolean',

            // Branches
            'branch_ids' => 'sometimes|nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
'removed_warehouse_ids' => 'sometimes|array',
'removed_warehouse_ids.*' => 'integer|min:1',
            // Units
            'units' => 'sometimes|nullable|array',
            'units.*.unit_id' => 'required|integer|exists:units,id',
            'units.*.cost_price' => 'nullable|numeric|min:0',
            'units.*.sell_price' => 'nullable|numeric|min:0',
            'units.*.barcode' => 'nullable|string',
            'units.*.colors' => 'nullable|array',
            'units.*.colors.*.color_id' => 'required|integer|exists:colors,id',
            'units.*.colors.*.stock' => 'nullable|numeric|min:0',
        ];
    }

protected function prepareForValidation(): void
{
   foreach (['warehouse_stocks', 'warehouse_ids', 'removed_warehouse_ids', 'branch_ids', 'units'] as $field) {
        $value = $this->input($field);

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([$field => $decoded]);
            }
        }
    }
    

    // ✅ تحويل [{warehouse_id: 7, qty: 2}, ...] إلى {7: 2, ...}
    $stocks = $this->input('warehouse_stocks');

    if (is_array($stocks) && !empty($stocks) && array_is_list($stocks)) {
        $map = [];

        foreach ($stocks as $row) {
            if (!is_array($row)) {
                return; // شكل غير مدعوم، نسيبه يفشل في الـ validation
            }

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $stocks = $this->input('warehouse_stocks', []);
            $warehouseIds = $this->input('warehouse_ids', []);

            if (!is_array($stocks) || !is_array($warehouseIds)) {
                return;
            }

            // امنع إرسال warehouse_stocks كقائمة [10, 15]
          // ارفض فقط لو العناصر نفسها مش أرقام (يعني قائمة كائنات زي [{warehouse_id, qty}])
foreach ($stocks as $value) {
    if (is_array($value) || is_object($value)) {
        $validator->errors()->add(
            'warehouse_stocks',
            'يجب إرسال كميات المخازن ككائن مفاتيحه هي IDs المخازن، وليس كمصفوفة كميات.'
        );
        return;
    }
}

            $selectedIds = array_map('strval', $warehouseIds);

            foreach ($stocks as $warehouseId => $quantity) {
                $id = (string) $warehouseId;

                // المفتاح لازم يكون رقم مخزن موجب
                if (!ctype_digit($id) || (int) $id <= 0) {
                    $validator->errors()->add(
                        "warehouse_stocks.$id",
                        "Invalid warehouse ID: $id"
                    );

                    continue;
                }

                // كل مخزن له كمية لازم يكون موجودًا في warehouse_ids
              if ($this->has('warehouse_ids') && !in_array($id, $selectedIds, true)) {
    $validator->errors()->add(
        "warehouse_stocks.$id",
        "Warehouse $id is missing from warehouse_ids."
    );
}
            }
        });
    }
}