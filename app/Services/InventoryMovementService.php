<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryMovementService
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /**
     * Apply a signed stock delta and persist an auditable movement row.
     * The product stock remains the aggregate fallback while the selected
     * product-unit/color row is updated whenever a unit and color are provided.
     */
    public function apply(array $data): InventoryMovement
    {
        return $this->database->transaction(function () use ($data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $delta = (float) $data['quantity_delta'];
            $productUnitId = $data['product_unit_id'] ?? null;

            if ($productUnitId) {
                $unitExists = DB::table('product_units')->where('id', $productUnitId)->exists();
                if (!$unitExists) {
                    $productUnitId = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $productUnitId)
                        ->value('id');
                }
            }

            if ($delta < 0 && (float) $product->stock + $delta < 0) {
                throw new RuntimeException("Insufficient stock for product {$product->id}");
            }

            $product->stock = (float) $product->stock + $delta;
            $product->save();

            if (!empty($data['warehouse_id'])) {
                $warehouseRow = DB::table('product_warehouse')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $data['warehouse_id'])
                    ->lockForUpdate()
                    ->first();
                $warehouseStock = (float) ($warehouseRow?->stock ?? 0) + $delta;
                if ($warehouseStock < 0) {
                    throw new RuntimeException('Insufficient stock in selected warehouse');
                }
                if ($warehouseRow) {
                    DB::table('product_warehouse')->where('id', $warehouseRow->id)->update([
                        'stock' => $warehouseStock,
                        'updated_at' => now(),
                    ]);
                } elseif ($delta > 0) {
                    DB::table('product_warehouse')->insert([
                        'product_id' => $product->id,
                        'warehouse_id' => $data['warehouse_id'],
                        'stock' => $warehouseStock,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (!empty($productUnitId) && !empty($data['color_id'])) {
                $variant = DB::table('product_unit_colors')
                    ->where('product_unit_id', $productUnitId)
                    ->where('color_id', $data['color_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$variant) {
                    if ($delta < 0) {
                        throw new RuntimeException('Selected product color/variant was not found');
                    }

                    DB::table('product_unit_colors')->insert([
                        'product_unit_id' => $productUnitId,
                        'color_id' => $data['color_id'],
                        'stock' => $delta,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $variantStock = (float) $variant->stock + $delta;
                    if ($variantStock < 0) {
                        throw new RuntimeException('Insufficient stock for selected product color/variant');
                    }

                    DB::table('product_unit_colors')
                        ->where('id', $variant->id)
                        ->update(['stock' => $variantStock, 'updated_at' => now()]);
                }
            }

            return InventoryMovement::create([
                'product_id' => $product->id,
                'product_unit_id' => $productUnitId,
                'size_id' => $data['size_id'] ?? null,
                'color_id' => $data['color_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'movement_type' => $data['movement_type'],
                'quantity_delta' => $delta,
                'balance_after' => $product->stock,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $data['created_by'] ?? optional(auth()->user())->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }
}
