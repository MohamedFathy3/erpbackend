<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('invoice_item_id')->nullable()->after('return_invoice_id')->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('automotive_service_id')->nullable()->after('product_id')->constrained('automotive_services')->nullOnDelete();
        });

        DB::transaction(fn () => $this->restoreLegacyWarehouseStock());
    }

    public function down(): void
    {
        if (DB::table('return_items')->whereNull('product_id')->exists()) {
            throw new RuntimeException('Cannot roll back while automotive service returns exist.');
        }

        Schema::table('return_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_item_id');
            $table->dropConstrainedForeignId('automotive_service_id');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }

    private function restoreLegacyWarehouseStock(): void
    {
        if (!Schema::hasTable('inventory_movements') || !Schema::hasTable('product_warehouse')) {
            return;
        }

        $returnModel = 'App\\Models\\ReturnInvoice';
        $invoiceModel = 'App\\Models\\Invoice';
        $returns = DB::table('return_invoices')
            ->where(fn ($query) => $query->whereNull('workflow_status')
                ->orWhere('workflow_status', '!=', 'cancelled'))
            ->orderBy('id')
            ->get(['id', 'invoice_id']);

        foreach ($returns as $return) {
            $invoice = DB::table('invoices')->where('id', $return->invoice_id)->first(['id', 'tenant_id', 'branch_id']);
            if (!$invoice) {
                continue;
            }

            $returnItems = DB::table('return_items')
                ->where('return_invoice_id', $return->id)
                ->whereNotNull('product_id')
                ->get();

            foreach ($returnItems->groupBy('product_id') as $productId => $items) {
                $hasReturnMovement = DB::table('inventory_movements')
                    ->where('reference_type', $returnModel)
                    ->where('reference_id', $return->id)
                    ->where('product_id', $productId)
                    ->where('quantity_delta', '>', 0)
                    ->exists();
                if ($hasReturnMovement) {
                    continue;
                }

                $quantity = 0.0;
                foreach ($items as $item) {
                    $sourceItem = DB::table('invoice_items')
                        ->where('invoice_id', $invoice->id)
                        ->where('product_id', $productId)
                        ->where('color', $item->color)
                        ->where('size', $item->size)
                        ->first(['meter_quantity']);
                    $quantity += (float) $item->quantity * (float) ($sourceItem?->meter_quantity ?: 1);
                }
                if ($quantity <= 0) {
                    continue;
                }

                $soldByWarehouse = DB::table('inventory_movements')
                    ->where('reference_type', $invoiceModel)
                    ->where('reference_id', $invoice->id)
                    ->where('product_id', $productId)
                    ->where('movement_type', 'sale')
                    ->where('quantity_delta', '<', 0)
                    ->whereNotNull('warehouse_id')
                    ->select('warehouse_id', DB::raw('SUM(ABS(quantity_delta)) as quantity'))
                    ->groupBy('warehouse_id')
                    ->orderBy('warehouse_id')
                    ->get();

                $returnedByWarehouse = DB::table('inventory_movements')
                    ->join('return_invoices', function ($join) use ($invoice, $returnModel) {
                        $join->on('return_invoices.id', '=', 'inventory_movements.reference_id')
                            ->where('inventory_movements.reference_type', '=', $returnModel)
                            ->where('return_invoices.invoice_id', '=', $invoice->id)
                            ->where(fn ($query) => $query->whereNull('return_invoices.workflow_status')
                                ->orWhere('return_invoices.workflow_status', '!=', 'cancelled'));
                    })
                    ->where('inventory_movements.product_id', $productId)
                    ->where('inventory_movements.quantity_delta', '>', 0)
                    ->whereNotNull('inventory_movements.warehouse_id')
                    ->select('inventory_movements.warehouse_id', DB::raw('SUM(inventory_movements.quantity_delta) as quantity'))
                    ->groupBy('inventory_movements.warehouse_id')
                    ->get()
                    ->keyBy('warehouse_id');

                $allocations = [];
                $remaining = $quantity;
                foreach ($soldByWarehouse as $sale) {
                    $available = max(0, (float) $sale->quantity - (float) ($returnedByWarehouse->get($sale->warehouse_id)->quantity ?? 0));
                    $allocation = min($remaining, $available);
                    if ($allocation > 0) {
                        $allocations[(int) $sale->warehouse_id] = $allocation;
                        $remaining -= $allocation;
                    }
                    if ($remaining <= 0) {
                        break;
                    }
                }

                if ($remaining > 0) {
                    $warehouseQuery = DB::table('warehouses')
                        ->where('tenant_id', $invoice->tenant_id)
                        ->whereNull('deleted_at');
                    if ($invoice->branch_id) {
                        $warehouseQuery->where('branch_id', $invoice->branch_id);
                    }
                    $warehouseIds = $warehouseQuery->orderBy('id')->pluck('id');
                    if ($warehouseIds->isEmpty()) {
                        $warehouseIds = DB::table('warehouses')
                            ->where('tenant_id', $invoice->tenant_id)
                            ->whereNull('deleted_at')
                            ->orderBy('id')
                            ->pluck('id');
                    }
                    if ($warehouseIds->isNotEmpty()) {
                        $warehouseId = (int) $warehouseIds->first();
                        $allocations[$warehouseId] = ($allocations[$warehouseId] ?? 0) + $remaining;
                        $remaining = 0;
                    }
                }

                $productCost = (float) DB::table('products')->where('id', $productId)->value('cost');
                foreach ($allocations as $warehouseId => $allocatedQuantity) {
                    $stockRow = DB::table('product_warehouse')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $warehouseId)
                        ->first();

                    if ($stockRow) {
                        DB::table('product_warehouse')
                            ->where('id', $stockRow->id)
                            ->increment('stock', $allocatedQuantity, ['updated_at' => now()]);
                        $balance = (float) $stockRow->stock + $allocatedQuantity;
                    } else {
                        $attributes = [
                            'product_id' => $productId,
                            'warehouse_id' => $warehouseId,
                            'stock' => $allocatedQuantity,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        if (Schema::hasColumn('product_warehouse', 'tenant_id')) {
                            $attributes['tenant_id'] = $invoice->tenant_id;
                        }
                        DB::table('product_warehouse')->insert($attributes);
                        $balance = $allocatedQuantity;
                    }

                    $movement = [
                        'product_id' => $productId,
                        'branch_id' => $invoice->branch_id,
                        'warehouse_id' => $warehouseId,
                        'movement_type' => 'return',
                        'quantity_delta' => $allocatedQuantity,
                        'balance_after' => $balance,
                        'reference_type' => $returnModel,
                        'reference_id' => $return->id,
                        'unit_cost' => $productCost,
                        'total_cost' => round($allocatedQuantity * $productCost, 2),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('inventory_movements', 'notes')) {
                        $movement['notes'] = 'Legacy POS return warehouse stock repair';
                    } elseif (Schema::hasColumn('inventory_movements', 'note')) {
                        $movement['note'] = 'Legacy POS return warehouse stock repair';
                    }
                    if (Schema::hasColumn('inventory_movements', 'tenant_id')) {
                        $movement['tenant_id'] = $invoice->tenant_id;
                    }
                    DB::table('inventory_movements')->insert($movement);
                }
            }
        }
    }
};
