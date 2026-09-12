<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryVariantStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryMovementService
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /**
     * Updates aggregate, warehouse, legacy color and exact variant balances
     * inside one transaction, then records an auditable movement.
     */
    public function apply(array $data): InventoryMovement
    {
        return $this->database->transaction(function () use ($data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $delta = (float) $data['quantity_delta'];
            $productUnitId = $data['product_unit_id'] ?? null;
            $sizeId = $data['size_id'] ?? null;
            $colorId = $data['color_id'] ?? null;
            $branchId = $data['branch_id'] ?? null;
            $warehouseId = $data['warehouse_id'] ?? null;

            if ($productUnitId) {
                $unitBelongsToProduct = DB::table('product_units')
                    ->where('id', $productUnitId)
                    ->where('product_id', $product->id)
                    ->exists();
                if (!$unitBelongsToProduct) {
                    throw new RuntimeException('Selected product unit does not belong to this product');
                }
            }

            $this->updateAggregateProduct($product, $delta);
            $this->updateWarehouseStock($product->id, $warehouseId, $delta);
            $this->updateLegacyColorStock($productUnitId, $colorId, $delta);

            $variantStock = null;
            if ($productUnitId || $sizeId || $colorId || $branchId || $warehouseId) {
                $variantStock = $this->updateVariantStock([
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnitId,
                    'size_id' => $sizeId,
                    'color_id' => $colorId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                ], $delta);
            }

            $actor = auth()->user();
            $createdBy = $data['created_by'] ?? ($actor instanceof User ? $actor->id : null);
            if ($createdBy !== null && !User::query()->whereKey($createdBy)->exists()) {
                $createdBy = null;
            }

            return InventoryMovement::create([
                'product_id' => $product->id,
                'product_unit_id' => $productUnitId,
                'size_id' => $sizeId,
                'color_id' => $colorId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $data['movement_type'],
                'quantity_delta' => $delta,
                'balance_after' => $variantStock?->stock ?? $product->stock,
                'inventory_variant_stock_id' => $variantStock?->id,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $createdBy,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    private function updateAggregateProduct(Product $product, float $delta): void
    {
        $newStock = (float) $product->stock + $delta;
        if ($newStock < 0) {
            throw new RuntimeException("Insufficient stock for product {$product->id}");
        }
        $product->stock = $newStock;
        $product->save();
    }

    private function updateWarehouseStock(?int $productId, ?int $warehouseId, float $delta): void
    {
        if (!$productId || !$warehouseId) {
            return;
        }

        $row = DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();
        $newStock = (float) ($row?->stock ?? 0) + $delta;
        if ($newStock < 0) {
            throw new RuntimeException('Insufficient stock in selected warehouse');
        }

        if ($row) {
            DB::table('product_warehouse')->where('id', $row->id)->update([
                'stock' => $newStock,
                'updated_at' => now(),
            ]);
        } elseif ($delta > 0) {
            DB::table('product_warehouse')->insert([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'stock' => $newStock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function updateLegacyColorStock(?int $productUnitId, ?int $colorId, float $delta): void
    {
        if (!$productUnitId || !$colorId) {
            return;
        }

        $row = DB::table('product_unit_colors')
            ->where('product_unit_id', $productUnitId)
            ->where('color_id', $colorId)
            ->lockForUpdate()
            ->first();
        $newStock = (float) ($row?->stock ?? 0) + $delta;
        if ($newStock < 0) {
            throw new RuntimeException('Insufficient stock for selected product color');
        }

        if ($row) {
            DB::table('product_unit_colors')->where('id', $row->id)->update([
                'stock' => $newStock,
                'updated_at' => now(),
            ]);
        } elseif ($delta > 0) {
            DB::table('product_unit_colors')->insert([
                'product_unit_id' => $productUnitId,
                'color_id' => $colorId,
                'stock' => $newStock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function updateVariantStock(array $identity, float $delta): InventoryVariantStock
    {
        $identityKey = $this->identityKey($identity);
        $tenantId = auth()->user()?->tenant_id
            ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
        $row = InventoryVariantStock::withoutGlobalScopes()
            ->where('identity_key', $identityKey)
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->where(function ($tenantQuery) use ($tenantId): void {
                    $tenantQuery->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                });
            })
            ->lockForUpdate()
            ->first();

        if ($row && $row->tenant_id === null && $tenantId !== null) {
            $row->tenant_id = $tenantId;
            $row->save();
        }

        if (!$row) {
            // Existing stock is not assigned to a size retrospectively. Use the
            // old aggregate only as a compatibility baseline on first touch.
            $baseline = $this->legacyBaseline($identity);
            $newStock = $baseline + $delta;
            if ($newStock < 0) {
                throw new RuntimeException('Insufficient stock for selected product variant');
            }

            return InventoryVariantStock::create($identity + [
                'identity_key' => $identityKey,
                'stock' => $newStock,
            ]);
        }

        $newStock = (float) $row->stock + $delta;
        if ($newStock < 0) {
            throw new RuntimeException('Insufficient stock for selected product variant');
        }
        $row->update(['stock' => $newStock]);
        return $row->refresh();
    }

    private function identityKey(array $identity): string
    {
        return implode(':', array_map(static fn ($value) => $value === null ? '0' : (string) $value, [
            $identity['product_id'],
            $identity['product_unit_id'],
            $identity['size_id'],
            $identity['color_id'],
            $identity['branch_id'],
            $identity['warehouse_id'],
        ]));
    }

    private function legacyBaseline(array $identity): float
    {
        if (!empty($identity['product_unit_id']) && !empty($identity['color_id'])) {
            $colorStock = DB::table('product_unit_colors')
                ->where('product_unit_id', $identity['product_unit_id'])
                ->where('color_id', $identity['color_id'])
                ->sum('stock');
            if ($colorStock > 0) {
                return (float) $colorStock;
            }
        }

        if (!empty($identity['warehouse_id'])) {
            return (float) DB::table('product_warehouse')
                ->where('product_id', $identity['product_id'])
                ->where('warehouse_id', $identity['warehouse_id'])
                ->value('stock');
        }

        return 0.0;
    }
}
