<?php

namespace App\Manufacturing\Services;

use App\Manufacturing\Models\BomVersion;
use App\Manufacturing\Models\ManufacturingEquipment;
use App\Manufacturing\Models\ManufacturingCostEntry;
use App\Manufacturing\Models\ManufacturingOperation;
use App\Manufacturing\Models\ManufacturingOperationLog;
use App\Manufacturing\Models\ManufacturingOrder;
use App\Manufacturing\Models\ManufacturingOrderMaterial;
use App\Manufacturing\Models\ManufacturingOrderStatusHistory;
use App\Manufacturing\Models\ManufacturingStockMovement;
use App\Manufacturing\Models\QualityInspection;
use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\AccountLedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ManufacturingService
{
    public function __construct(
        private readonly ?int $userId = null,
        private readonly ?int $tenantId = null,
    )
    {
    }

    public function createOrder(array $data): ManufacturingOrder
    {
        return DB::transaction(function () use ($data): ManufacturingOrder {
            $product = Product::findOrFail($data['product_id']);
            if ($product->product_type === 'service') {
                throw new RuntimeException('A service cannot be produced as a stocked finished good.');
            }

            $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->first();
            if (!$warehouse) {
                throw new RuntimeException('Warehouse not found.');
            }
            if ($this->tenantId && (int) $warehouse->tenant_id !== $this->tenantId) {
                throw new RuntimeException('Warehouse is outside the current workspace.');
            }

            $branchId = $data['branch_id'] ?? $warehouse->branch_id;
            if ($branchId !== null && (int) $branchId !== (int) $warehouse->branch_id) {
                throw new RuntimeException('Warehouse does not belong to branch.');
            }

            $bom = !empty($data['bom_version_id'])
                ? BomVersion::findOrFail($data['bom_version_id'])
                : BomVersion::where('product_id', $product->id)
                    ->where('status', 'active')
                    ->latest('id')
                    ->first();

            if ($bom && (int) $bom->product_id !== (int) $product->id) {
                throw new RuntimeException('The selected BOM belongs to a different product.');
            }
            if ($bom && $bom->branch_id && (int) $bom->branch_id !== (int) $branchId) {
                throw new RuntimeException('The selected BOM belongs to a different branch.');
            }
            if ($bom && $bom->status !== 'active') {
                throw new RuntimeException('New production orders require an active BOM revision.');
            }
            if (!$bom || !$bom->items()->exists()) {
                throw new RuntimeException('A production order requires an active BOM with material components.');
            }
            $today = now()->toDateString();
            if ($bom && (($bom->effective_from && $bom->effective_from > $today)
                || ($bom->effective_to && $bom->effective_to < $today))) {
                throw new RuntimeException('The selected BOM revision is outside its effective dates.');
            }

            if (!empty($data['source_invoice_id'])) {
                $invoiceQuery = DB::table('invoices')->where('id', $data['source_invoice_id']);
                if ($this->tenantId) {
                    $invoiceQuery->where('tenant_id', $this->tenantId);
                }
                if (!$invoiceQuery->exists()) {
                    throw new RuntimeException('The source invoice is outside the current workspace.');
                }

                $alreadyLinked = ManufacturingOrder::where('source_invoice_id', $data['source_invoice_id'])
                    ->when(
                        !empty($data['source_invoice_item_id']),
                        fn ($query) => $query->where('source_invoice_item_id', $data['source_invoice_item_id']),
                        fn ($query) => $query->where('product_id', $product->id),
                    )
                    ->whereNotIn('status', ['cancelled'])
                    ->exists();
                if ($alreadyLinked) {
                    throw new RuntimeException('A production order already exists for this invoice product.');
                }
            }

            $order = ManufacturingOrder::create([
                'order_number' => $data['order_number'] ?? 'MO-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'product_id' => $product->id,
                'bom_version_id' => $bom?->id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id,
                'source_invoice_id' => $data['source_invoice_id'] ?? null,
                'source_invoice_item_id' => $data['source_invoice_item_id'] ?? null,
                'source_sales_order_id' => $data['source_sales_order_id'] ?? null,
                'planned_quantity' => $data['planned_quantity'],
                'dimensions' => $data['dimensions'] ?? $product->dimensions,
                'planned_cost' => $data['planned_cost'] ?? 0,
                'status' => 'draft',
                'planned_at' => $data['planned_at'] ?? null,
                'created_by' => $this->persistedUserId(),
                'notes' => $data['notes'] ?? null,
            ]);

            if ($bom) {
                $this->explodeBom(
                    $order,
                    $bom,
                    (float) $data['planned_quantity'] / max((float) $bom->yield_quantity, 0.000001),
                );
            }

            foreach ($data['operations'] ?? [] as $index => $operation) {
                if (!empty($operation['equipment_id'])) {
                    $equipment = ManufacturingEquipment::findOrFail($operation['equipment_id']);
                    if ($equipment->branch_id && (int) $equipment->branch_id !== (int) $branchId) {
                        throw new RuntimeException('The selected equipment belongs to a different branch.');
                    }
                }

                $order->operations()->create([
                    'sequence' => $operation['sequence'] ?? $index + 1,
                    'name' => $operation['name'],
                    'equipment_id' => $operation['equipment_id'] ?? null,
                    'planned_minutes' => $operation['planned_minutes'] ?? 0,
                ]);
            }

            $this->transition($order, 'planned', 'Production order created');

            return $order->load(['materials.product', 'operations', 'bomVersion']);
        });
    }

    public function createOrderFromInvoice(int $invoiceId, int $invoiceItemId, int $warehouseId, array $operations = []): ManufacturingOrder
    {
        $itemQuery = DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->where('id', $invoiceItemId);
        if ($this->tenantId) {
            $itemQuery->where('tenant_id', $this->tenantId);
        }
        $item = $itemQuery->first();

        if (!$item) {
            throw new RuntimeException('Invoice item not found.');
        }
        if (($item->item_type ?? 'product') !== 'product' || !$item->product_id) {
            throw new RuntimeException('Only stocked product invoice items can be manufactured.');
        }

        $quantity = (float) ($item->meter_quantity ?: $item->quantity);
        if ($quantity <= 0) {
            throw new RuntimeException('The invoice item has no valid production quantity.');
        }

        return $this->createOrder([
            'product_id' => $item->product_id,
            'warehouse_id' => $warehouseId,
            'source_invoice_id' => $invoiceId,
            'source_invoice_item_id' => $invoiceItemId,
            'planned_quantity' => $quantity,
            'operations' => $operations,
        ]);
    }

    private function explodeBom(ManufacturingOrder $order, BomVersion $bom, float $factor, array $visited = []): void
    {
        if (in_array($bom->id, $visited, true)) {
            throw new RuntimeException('Circular BOM references are not allowed.');
        }

        $visited[] = $bom->id;
        $bom->loadMissing('items.component', 'items.childBom.items.component');

        foreach ($bom->items as $item) {
            $quantity = $factor
                * (float) $item->quantity
                * (1 + ((float) $item->scrap_percent / 100));
            $component = $item->component;

            if (!$component) {
                throw new RuntimeException('A BOM component product no longer exists.');
            }

            $itemUnit = $item->unit ?: $component->base_unit ?: $component->stock_unit;
            if ($itemUnit && $component->base_unit && $itemUnit === $component->base_unit) {
                $quantity *= (float) ($component->conversion_factor ?: 1);
            } elseif ($itemUnit && $component->stock_unit && $itemUnit !== $component->stock_unit) {
                throw new RuntimeException("BOM unit {$itemUnit} does not match the stock unit for {$component->name}.");
            }

            if ($item->child_bom_version_id) {
                $childBom = $item->childBom;
                if (!$childBom || (int) $childBom->product_id !== (int) $component->id) {
                    throw new RuntimeException('A nested BOM must belong to its component product.');
                }
                $this->explodeBom(
                    $order,
                    $childBom,
                    $quantity / max((float) $childBom->yield_quantity, 0.000001),
                    $visited,
                );
                continue;
            }

            $order->materials()->create([
                'bom_item_id' => $item->id,
                'product_id' => $component->id,
                'required_quantity' => $quantity,
                'unit_cost' => (float) ($component->cost ?? 0),
            ]);
        }
    }

    public function transition(ManufacturingOrder $order, string $status, ?string $reason = null, ?string $key = null): ManufacturingOrder
    {
        $allowed = [
            'draft' => ['planned', 'cancelled'],
            'planned' => ['reserved', 'draft', 'cancelled'],
            'reserved' => ['in_progress', 'paused', 'cancelled'],
            'in_progress' => ['quality_hold', 'paused', 'completed', 'cancelled'],
            'paused' => ['reserved', 'in_progress', 'cancelled'],
            'quality_hold' => ['in_progress', 'cancelled'],
            'completed' => ['closed'],
            'cancelled' => [],
            'closed' => [],
        ];

        if ($order->status === $status) {
            return $order->fresh();
        }
        if (!in_array($status, $allowed[$order->status] ?? [], true)) {
            throw new RuntimeException("Invalid status transition {$order->status} -> {$status}.");
        }

        $previousStatus = $order->status;
        $timestamps = match ($status) {
            'in_progress' => ['started_at' => $order->started_at ?? now()],
            'completed' => ['completed_at' => now()],
            'closed' => ['closed_at' => now()],
            default => [],
        };
        $order->update(['status' => $status] + $timestamps);
        ManufacturingOrderStatusHistory::create([
            'manufacturing_order_id' => $order->id,
            'from_status' => $previousStatus,
            'to_status' => $status,
            'reason' => $reason,
            'created_by' => $this->persistedUserId(),
            'idempotency_key' => $key,
        ]);

        return $order->fresh();
    }

    public function pause(ManufacturingOrder $order, string $reason, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'pause', function () use ($order, $reason, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $reason, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if (!in_array($order->status, ['reserved', 'in_progress'], true)) {
                    throw new RuntimeException('Only reserved or active orders can be stopped.');
                }
                return $this->transition($order, 'paused', $reason, $key);
            });
        });
    }

    public function resume(ManufacturingOrder $order, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'resume', function () use ($order, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if ($order->status !== 'paused') {
                    throw new RuntimeException('Only a stopped order can be resumed.');
                }
                $previous = $order->statusHistory()->where('to_status', 'paused')->latest('id')->value('from_status');
                $resumeStatus = in_array($previous, ['reserved', 'in_progress'], true) ? $previous : 'in_progress';
                return $this->transition($order, $resumeStatus, 'Production resumed', $key);
            });
        });
    }

    public function availability(ManufacturingOrder $order): array
    {
        $requirements = $order->materials->groupBy('product_id');
        $rows = [];

        foreach ($requirements as $productId => $materials) {
            $product = $materials->first()->product;
            if ($product?->product_type === 'service') {
                continue;
            }

            $stock = (float) (DB::table('product_warehouse')
                ->where(['product_id' => $productId, 'warehouse_id' => $order->warehouse_id])
                ->value('stock') ?? 0);
            $otherReservations = $this->reservedByOtherOrders($order, (int) $productId);
            $available = max(0, $stock - $otherReservations);
            $required = (float) $materials->sum(fn (ManufacturingOrderMaterial $material): float =>
                max(0, (float) $material->required_quantity - (float) $material->reserved_quantity - (float) $material->issued_quantity)
            );

            $rows[] = [
                'product_id' => (int) $productId,
                'product_name' => $product?->name,
                'required' => $required,
                'on_hand' => $stock,
                'reserved_by_other_orders' => $otherReservations,
                'available' => $available,
                'shortage' => max(0, $required - $available),
                'material_ids' => $materials->pluck('id')->values(),
            ];
        }

        return [
            'available' => collect($rows)->every(fn (array $row): bool => $row['shortage'] <= 0),
            'items' => $rows,
        ];
    }

    public function reserve(ManufacturingOrder $order, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'reserve', function () use ($order): ManufacturingOrder {
            return DB::transaction(function () use ($order): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if (!in_array($order->status, ['draft', 'planned', 'in_progress'], true)) {
                    throw new RuntimeException('Materials can only be reserved for a draft, planned, or active order.');
                }

                $order->loadMissing('materials.product');
                if ($order->materials->filter(fn ($material) => $material->product && $material->product->product_type !== 'service')->isEmpty()) {
                    throw new RuntimeException('This order has no BOM material components to reserve. Create a new order from an active BOM.');
                }
                if ($order->status === 'draft') {
                    $order = $this->transition($order, 'planned', 'Order planned');
                }

                foreach ($order->materials->groupBy('product_id') as $productId => $materials) {
                    $product = $materials->first()->product;
                    if ($product?->product_type === 'service') {
                        continue;
                    }

                    DB::table('product_warehouse')
                        ->where(['product_id' => $productId, 'warehouse_id' => $order->warehouse_id])
                        ->lockForUpdate()
                        ->first();
                }

                $availability = $this->availability($order);
                if (!$availability['available']) {
                    throw new RuntimeException('Insufficient material availability.');
                }

                $reservedTotal = 0.0;
                foreach ($order->materials as $material) {
                    if ($material->product?->product_type === 'service') {
                        continue;
                    }
                    $need = max(
                        0,
                        (float) $material->required_quantity
                            - (float) $material->reserved_quantity
                            - (float) $material->issued_quantity,
                    );
                    if ($need > 0) {
                        $material->increment('reserved_quantity', $need);
                        $reservedTotal += $need;
                    }
                }

                if ($reservedTotal > 0) {
                    $order->increment('reserved_quantity', $reservedTotal);
                }

                return $order->status === 'in_progress'
                    ? $order->fresh()
                    : $this->transition($order->fresh(), 'reserved', 'Materials reserved');
            });
        });
    }

    public function start(ManufacturingOrder $order, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'start', function () use ($order): ManufacturingOrder {
            return DB::transaction(function () use ($order): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                return $this->transition($order, 'in_progress', 'Production started');
            });
        });
    }

    public function issue(ManufacturingOrder $order, array $items, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'issue', function () use ($order, $items, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $items, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if (!in_array($order->status, ['reserved', 'in_progress'], true)) {
                    throw new RuntimeException('Materials can only be issued to a reserved or active order.');
                }

                foreach ($this->groupItems($items) as $materialId => $quantity) {
                    $material = $order->materials()->lockForUpdate()->findOrFail($materialId);
                    $quantity = (float) $quantity;
                    $remaining = max(
                        0,
                        (float) $material->required_quantity
                            - (float) $material->issued_quantity
                            + (float) $material->returned_quantity,
                    );
                    $isService = $material->product?->product_type === 'service';
                    if ($quantity > $remaining || (!$isService && $quantity > (float) $material->reserved_quantity)) {
                        throw new RuntimeException('Issue quantity exceeds the reserved or required quantity.');
                    }

                    $product = $material->product;
                    $unitCost = (float) ($material->unit_cost ?: $product?->cost ?: 0);
                    if ($product?->product_type !== 'service') {
                        $this->adjustStock($order, $material->product_id, -$quantity, $unitCost, 'issue', 'Manufacturing material issue');
                    } else {
                        ManufacturingCostEntry::create([
                            'manufacturing_order_id' => $order->id,
                            'cost_type' => 'service',
                            'quantity' => $quantity,
                            'unit_cost' => $unitCost,
                            'amount' => $quantity * $unitCost,
                            'notes' => 'Non-stock service consumed by manufacturing.',
                        ]);
                    }

                    $material->increment('issued_quantity', $quantity);
                    if (!$isService) {
                        $material->decrement('reserved_quantity', $quantity);
                        $order->decrement('reserved_quantity', $quantity);
                    }

                    if ($product?->product_type !== 'service') {
                        $this->recordManufacturingMovement($order, $material->product_id, 'issue', $quantity, $key . ':' . $materialId, $unitCost);
                        ManufacturingCostEntry::create([
                            'manufacturing_order_id' => $order->id,
                            'cost_type' => 'material',
                            'quantity' => $quantity,
                            'unit_cost' => $unitCost,
                            'amount' => $quantity * $unitCost,
                            'notes' => 'Material issued to production.',
                        ]);
                    }
                }

                return $order->fresh();
            });
        });
    }

    public function returnMaterial(ManufacturingOrder $order, array $items, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'return', function () use ($order, $items, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $items, $key): ManufacturingOrder {
                if (in_array($order->status, ['cancelled', 'closed'], true)) {
                    throw new RuntimeException('Materials cannot be returned for a cancelled or closed order.');
                }
                foreach ($this->groupItems($items) as $materialId => $quantity) {
                    $material = $order->materials()->lockForUpdate()->findOrFail($materialId);
                    $quantity = (float) $quantity;
                    if ($material->product?->product_type === 'service') {
                        throw new RuntimeException('Service quantities are not returned to inventory.');
                    }
                    $returnable = (float) $material->issued_quantity
                        - (float) $material->returned_quantity
                        - (float) $material->scrapped_quantity;
                    if ($quantity > $returnable) {
                        throw new RuntimeException('Return exceeds issued, unreturned, and unscrapped quantity.');
                    }

                    $this->adjustStock($order, $material->product_id, $quantity, (float) $material->unit_cost, 'return', 'Manufacturing material return');
                    $material->increment('returned_quantity', $quantity);
                    $this->recordManufacturingMovement($order, $material->product_id, 'return', $quantity, $key . ':' . $materialId, (float) $material->unit_cost);
                    ManufacturingCostEntry::create([
                        'manufacturing_order_id' => $order->id,
                        'cost_type' => 'material_return',
                        'quantity' => $quantity,
                        'unit_cost' => (float) $material->unit_cost,
                        'amount' => -$quantity * (float) $material->unit_cost,
                        'notes' => 'Unused material returned to stock.',
                    ]);
                }

                return $order->fresh();
            });
        });
    }

    public function scrap(ManufacturingOrder $order, array $items, string $key, ?int $operationId = null, ?string $reason = null): ManufacturingOrder
    {
        return $this->idempotent($key, 'scrap', function () use ($order, $items, $key, $operationId, $reason): ManufacturingOrder {
            return DB::transaction(function () use ($order, $items, $key, $operationId, $reason): ManufacturingOrder {
                if (in_array($order->status, ['cancelled', 'closed'], true)) {
                    throw new RuntimeException('Materials cannot be scrapped for a cancelled or closed order.');
                }
                if ($operationId !== null) {
                    $order->operations()->findOrFail($operationId);
                }
                foreach ($this->groupItems($items) as $materialId => $quantity) {
                    $material = $order->materials()->lockForUpdate()->findOrFail($materialId);
                    $quantity = (float) $quantity;
                    if ($material->product?->product_type === 'service') {
                        throw new RuntimeException('Service quantities cannot be scrapped as inventory.');
                    }
                    $scrappable = (float) $material->issued_quantity
                        - (float) $material->returned_quantity
                        - (float) $material->scrapped_quantity;
                    if ($quantity > $scrappable) {
                        throw new RuntimeException('Scrap exceeds issued, unreturned, and unscrapped quantity.');
                    }

                    $material->increment('scrapped_quantity', $quantity);
                    $order->increment('scrapped_quantity', $quantity);
                    $movement = $this->recordManufacturingMovement($order, $material->product_id, 'scrap', $quantity, $key . ':' . $materialId, (float) $material->unit_cost);
                    $movement->update(['manufacturing_operation_id' => $operationId, 'reason' => $reason]);
                    ManufacturingCostEntry::create([
                        'manufacturing_order_id' => $order->id,
                        'cost_type' => 'scrap',
                        'quantity' => $quantity,
                        'unit_cost' => (float) $material->unit_cost,
                        'amount' => $quantity * (float) $material->unit_cost,
                        'notes' => 'Material scrapped during manufacturing.',
                    ]);
                }

                return $order->fresh();
            });
        });
    }

    public function operation(ManufacturingOrder $order, int $operationId, string $action, array $data, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, $action, function () use ($order, $operationId, $action, $data, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $operationId, $action, $data, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if (!in_array($order->status, ['reserved', 'in_progress'], true)) {
                    throw new RuntimeException('Operations can only be recorded on a reserved or active order.');
                }
                $operation = $order->operations()->lockForUpdate()->findOrFail($operationId);
                $hasIncompletePrevious = $order->operations()
                    ->where('sequence', '<', $operation->sequence)
                    ->whereNotIn('status', ['completed', 'skipped'])
                    ->exists();

                if (in_array($action, ['start', 'complete'], true) && $hasIncompletePrevious) {
                    throw new RuntimeException('Operations must be completed in sequence.');
                }
                if ($action === 'start' && $operation->status !== 'pending') {
                    throw new RuntimeException('Only pending operations can be started.');
                }
                if ($action === 'complete' && $operation->status !== 'in_progress') {
                    throw new RuntimeException('An operation must be started before it can be completed.');
                }
                if ($action === 'start') {
                    $operation->update(['status' => 'in_progress']);
                } elseif ($action === 'complete') {
                    $operation->update([
                        'status' => 'completed',
                        'actual_minutes' => $data['actual_minutes'] ?? $operation->actual_minutes,
                    ]);
                } elseif (in_array($action, ['labor', 'machine'], true)) {
                    $minutes = (float) ($data['minutes'] ?? 0);
                    $cost = (float) ($data['cost'] ?? 0);
                    $operation->logs()->create([
                        'user_id' => $this->userId,
                        'action' => $action,
                        'minutes' => $minutes,
                        'labor_cost' => $action === 'labor' ? $cost : 0,
                        'machine_cost' => $action === 'machine' ? $cost : 0,
                        'idempotency_key' => $key,
                    ]);
                    ManufacturingCostEntry::create([
                        'manufacturing_order_id' => $order->id,
                        'manufacturing_operation_id' => $operation->id,
                        'cost_type' => $action,
                        'quantity' => $minutes / 60,
                        'unit_cost' => $minutes > 0 ? $cost / ($minutes / 60) : 0,
                        'amount' => $cost,
                        'notes' => ucfirst($action) . ' cost.',
                    ]);
                }

                if ($order->status === 'reserved') {
                    $this->transition($order, 'in_progress', 'First operation started');
                }

                return $order->fresh();
            });
        });
    }

    public function quality(ManufacturingOrder $order, array $data, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'quality', function () use ($order, $data): ManufacturingOrder {
            return DB::transaction(function () use ($order, $data): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if (!in_array($order->status, ['in_progress', 'quality_hold'], true)) {
                    throw new RuntimeException('Quality inspections require an active production order.');
                }
                $remainingQuantity = max(0, (float) $order->planned_quantity - (float) $order->produced_quantity);
                $inspectionQuantity = (float) ($data['quantity'] ?? $remainingQuantity);
                if ($inspectionQuantity <= 0 || $inspectionQuantity > $remainingQuantity) {
                    throw new RuntimeException('Inspected quantity cannot exceed the remaining planned production quantity (' . $remainingQuantity . ').');
                }

                if (!empty($data['manufacturing_operation_id'])) {
                    $inspectionOperation = $order->operations()->findOrFail($data['manufacturing_operation_id']);
                    if (!in_array($inspectionOperation->status, ['in_progress', 'completed'], true)) throw new RuntimeException('Quality inspection requires an active or completed operation.');
                } elseif ($order->operations()->whereNotIn('status', ['completed', 'skipped'])->exists()) {
                    throw new RuntimeException('Complete all operations or select the stage being inspected.');
                }

                QualityInspection::create([
                    'manufacturing_order_id' => $order->id,
                    'manufacturing_operation_id' => $data['manufacturing_operation_id'] ?? null,
                    'quantity' => $inspectionQuantity,
                    'result' => $data['result'],
                    'reason' => $data['reason'] ?? null,
                    'inspected_by' => $this->persistedUserId(),
                ]);

                if ($data['result'] === 'reject' && $order->status !== 'quality_hold') {
                    $this->transition($order, 'quality_hold', 'Quality rejected');
                } elseif (in_array($data['result'], ['pass', 'rework'], true) && $order->status === 'quality_hold') {
                    if ($data['result'] === 'rework') {
                        $lastOperation = $order->operations()->orderByDesc('sequence')->first();
                        $lastOperation?->update(['status' => 'pending']);
                    }
                    $this->transition($order, 'in_progress', $data['result'] === 'pass' ? 'Quality passed' : 'Rework required');
                }

                return $order->fresh();
            });
        });
    }

    public function receive(ManufacturingOrder $order, float $quantity, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'receive', function () use ($order, $quantity, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $quantity, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if ($order->status !== 'in_progress') {
                    throw new RuntimeException('Finished goods can only be received from an active order.');
                }
                if ((float) $quantity <= 0
                    || (float) $order->produced_quantity + $quantity > (float) $order->planned_quantity) {
                    throw new RuntimeException('Receipt quantity exceeds the outstanding production quantity.');
                }
                if ($order->operations()->whereNotIn('status', ['completed', 'skipped'])->exists()) {
                    throw new RuntimeException('All production operations must be completed before receipt.');
                }

                $lastInspection = $order->qualityInspections()->latest('id')->first();
                if (!$lastInspection || $lastInspection->result !== 'pass') {
                    throw new RuntimeException('A passing quality inspection is required before finished-goods receipt.');
                }
                if ((float) $lastInspection->quantity > (float) $order->planned_quantity) {
                    throw new RuntimeException('The latest inspection exceeds the order plan. Record a new inspection for the remaining quantity before receipt.');
                }
                if ((float) $quantity > (float) $lastInspection->quantity) {
                    throw new RuntimeException('Receipt quantity exceeds the last accepted inspection batch quantity.');
                }

                $unitCost = $this->totalOrderCost($order) / max((float) $order->planned_quantity, 0.000001);
                $this->adjustStock($order, $order->product_id, $quantity, $unitCost, 'receipt', 'Finished goods received from production');
                $this->recordManufacturingMovement($order, $order->product_id, 'receive', $quantity, $key, $unitCost);
                $order->increment('produced_quantity', $quantity);

                $order = $order->fresh();
                if ((float) $order->produced_quantity >= (float) $order->planned_quantity) {
                    $journalId = $this->postCompletionJournal($order);
                    $order->update(['actual_cost' => $this->totalOrderCost($order), 'completion_journal_entry_id' => $journalId]);
                    $this->transition($order->fresh(), 'completed', 'Finished goods received');
                }

                return $order->fresh();
            });
        });
    }

    public function cancel(ManufacturingOrder $order, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'cancel', function () use ($order, $key): ManufacturingOrder {
            return DB::transaction(function () use ($order, $key): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if ((float) $order->produced_quantity > 0) {
                    throw new RuntimeException('An order with received finished goods must be reversed through a return document, not cancelled.');
                }
                if (!in_array($order->status, ['draft', 'planned', 'reserved', 'in_progress', 'quality_hold', 'paused'], true)) {
                    throw new RuntimeException('This order cannot be cancelled.');
                }

                foreach ($order->materials as $material) {
                    $returnable = (float) $material->issued_quantity
                        - (float) $material->returned_quantity
                        - (float) $material->scrapped_quantity;
                    if ($returnable > 0 && $material->product?->product_type !== 'service') {
                        $this->adjustStock($order, $material->product_id, $returnable, (float) $material->unit_cost, 'return', 'Material reversal for cancelled manufacturing order');
                        $this->recordManufacturingMovement($order, $material->product_id, 'return', $returnable, $key . ':' . $material->id, (float) $material->unit_cost);
                        $material->increment('returned_quantity', $returnable);
                        ManufacturingCostEntry::create([
                            'manufacturing_order_id' => $order->id,
                            'cost_type' => 'material_return',
                            'quantity' => $returnable,
                            'unit_cost' => (float) $material->unit_cost,
                            'amount' => -$returnable * (float) $material->unit_cost,
                            'notes' => 'Material reversed when cancelling the production order.',
                        ]);
                    } elseif ($returnable > 0) {
                        ManufacturingCostEntry::create([
                            'manufacturing_order_id' => $order->id,
                            'cost_type' => 'service_return',
                            'quantity' => $returnable,
                            'unit_cost' => (float) $material->unit_cost,
                            'amount' => -$returnable * (float) $material->unit_cost,
                            'notes' => 'Unused service quantity reversed when cancelling the production order.',
                        ]);
                    }
                    $material->update(['reserved_quantity' => 0]);
                }

                $order->update(['reserved_quantity' => 0]);
                return $this->transition($order->fresh(), 'cancelled', 'Order cancelled and outstanding materials reversed', $key);
            });
        });
    }

    public function close(ManufacturingOrder $order, string $key): ManufacturingOrder
    {
        return $this->idempotent($key, 'close', function () use ($order): ManufacturingOrder {
            return DB::transaction(function () use ($order): ManufacturingOrder {
                $order = ManufacturingOrder::lockForUpdate()->findOrFail($order->id);
                if ($order->status !== 'completed') {
                    throw new RuntimeException('Only completed orders can be closed.');
                }
                if ((float) $order->reserved_quantity > 0
                    || $order->materials()->where('reserved_quantity', '>', 0)->exists()) {
                    throw new RuntimeException('Release or issue all reserved materials before closing.');
                }
                if ($order->materials()->whereRaw('issued_quantity < required_quantity')->exists()) {
                    throw new RuntimeException('Material balances are not reconciled; return unused quantities before closing.');
                }

                return $this->transition($order, 'closed', 'Production and cost balances reconciled', $key);
            });
        });
    }

    private function reservedByOtherOrders(ManufacturingOrder $order, int $productId): float
    {
        $query = DB::table('manufacturing_order_materials as materials')
            ->join('manufacturing_orders as orders', 'orders.id', '=', 'materials.manufacturing_order_id')
            ->where('materials.product_id', $productId)
            ->where('orders.warehouse_id', $order->warehouse_id)
            ->where('orders.id', '!=', $order->id)
            ->whereNotIn('orders.status', ['cancelled', 'closed', 'completed']);

        if ($order->tenant_id) {
            $query->where('orders.tenant_id', $order->tenant_id);
        }

        return (float) $query->sum('materials.reserved_quantity');
    }

    private function adjustStock(
        ManufacturingOrder $order,
        int $productId,
        float $delta,
        float $unitCost,
        string $movementType,
        string $notes,
    ): void {
        $product = Product::lockForUpdate()->findOrFail($productId);
        $warehouseRow = DB::table('product_warehouse')
            ->where(['product_id' => $productId, 'warehouse_id' => $order->warehouse_id])
            ->lockForUpdate()
            ->first();
        $before = (float) ($warehouseRow->stock ?? 0);
        $after = $before + $delta;
        if ($after < -0.000001) {
            throw new RuntimeException('Negative stock is not allowed.');
        }

        if ($warehouseRow) {
            $oldWarehouseCost = (float) ($warehouseRow->cost ?? $product->cost ?? 0);
            $newWarehouseCost = $after > 0 && $delta > 0
                ? (($before * $oldWarehouseCost) + ($delta * $unitCost)) / $after
                : $oldWarehouseCost;
            DB::table('product_warehouse')
                ->where('id', $warehouseRow->id)
                ->update(['stock' => max(0, $after), 'cost' => $newWarehouseCost, 'updated_at' => now()]);
        } elseif ($delta > 0) {
            DB::table('product_warehouse')->insert([
                'product_id' => $productId,
                'warehouse_id' => $order->warehouse_id,
                'stock' => $delta,
                'cost' => $unitCost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            throw new RuntimeException('No warehouse stock row exists for this product.');
        }

        $totalStock = (float) DB::table('product_warehouse')->where('product_id', $productId)->sum('stock');
        $product->update(['stock' => $totalStock]);
        if ($delta > 0 && $totalStock > 0) {
            $weightedCost = (float) DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->selectRaw('SUM(stock * COALESCE(cost, 0)) / NULLIF(SUM(stock), 0) as average_cost')
                ->value('average_cost');
            if ($weightedCost >= 0) {
                $product->update(['cost' => $weightedCost]);
            }
        }

        InventoryMovement::create([
            'product_id' => $productId,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'movement_type' => 'manufacturing_' . $movementType,
            'quantity_delta' => $delta,
            'balance_after' => max(0, $after),
            'reference_type' => ManufacturingOrder::class,
            'reference_id' => $order->id,
            'created_by' => $this->persistedUserId(),
            'notes' => $notes,
            'unit_cost' => $unitCost,
            'total_cost' => abs($delta) * $unitCost,
        ]);
    }

    private function recordManufacturingMovement(
        ManufacturingOrder $order,
        int $productId,
        string $type,
        float $quantity,
        string $key,
        float $unitCost,
    ): ManufacturingStockMovement {
        return ManufacturingStockMovement::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $productId,
            'warehouse_id' => $order->warehouse_id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'idempotency_key' => $key,
            'created_by' => $this->persistedUserId(),
        ]);
    }

    /** Return an actor id only when it exists in the users table referenced by manufacturing FKs. */
    private function persistedUserId(): ?int
    {
        if (!$this->userId) {
            return null;
        }

        return DB::table('users')->where('id', $this->userId)->exists()
            ? $this->userId
            : null;
    }

    private function totalOrderCost(ManufacturingOrder $order): float
    {
        return (float) ManufacturingCostEntry::where('manufacturing_order_id', $order->id)->sum('amount');
    }

    private function postCompletionJournal(ManufacturingOrder $order): ?int
    {
        $totalCost = $this->totalOrderCost($order);
        if ($totalCost <= 0) {
            return null;
        }

        $ledger = app(AccountLedgerService::class);
        $accounts = $ledger->manufacturingAccounts();
        $inventoryAccount = Account::query()->lockForUpdate()->findOrFail($accounts['inventory']->id);
        $workInProgressAccount = Account::query()->lockForUpdate()->findOrFail($accounts['work_in_progress']->id);

        $journal = \App\Models\JournalEntry::create([
            'entry_date' => now()->toDateString(),
            'description_ar' => "تكلفة إنتاج {$order->order_number}",
            'description_en' => "Production completion {$order->order_number}",
            'status' => 'posted',
        ]);
        $journal->lines()->createMany([
            ['account_id' => $inventoryAccount->id, 'debit' => $totalCost, 'credit' => 0, 'description' => 'Finished goods received from manufacturing'],
            ['account_id' => $workInProgressAccount->id, 'debit' => 0, 'credit' => $totalCost, 'description' => 'Close manufacturing work in progress'],
        ]);
        $ledger->updateTotals($inventoryAccount, $totalCost, 0);
        $ledger->updateTotals($workInProgressAccount, 0, $totalCost);
        ManufacturingCostEntry::where('manufacturing_order_id', $order->id)
            ->whereNull('journal_entry_id')
            ->update(['journal_entry_id' => $journal->id]);

        return $journal->id;
    }

    private function groupItems(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $id = (int) $item['material_id'];
            $grouped[$id] = ($grouped[$id] ?? 0) + (float) $item['quantity'];
        }
        return $grouped;
    }

    private function idempotent(string $key, string $action, callable $callback): mixed
    {
        if ($key === '') {
            throw new RuntimeException('idempotency_key is required.');
        }

        return DB::transaction(function () use ($key, $action, $callback): mixed {
            $userId = $this->userId ?? 0;
            $tenantId = $this->tenantId
                ?? auth()->user()?->tenant_id
                ?? (app()->bound('currentTenantId') ? app('currentTenantId') : null)
                ?? DB::table('tenants')->where('slug', 'default')->value('id');
            DB::table('manufacturing_idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'action' => $action,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'response' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = DB::table('manufacturing_idempotency_keys')
                ->where(['key' => $key, 'action' => $action, 'user_id' => $userId])
                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->first();

            if (!$record) {
                throw new RuntimeException('Unable to reserve the idempotency key.');
            }
            if ($record->response !== null) {
                return json_decode($record->response, true, flags: JSON_THROW_ON_ERROR);
            }

            $result = $callback();
            $response = $result instanceof Model ? $result->toArray() : $result;
            $encoded = json_encode($response, JSON_THROW_ON_ERROR);

            DB::table('manufacturing_idempotency_keys')
                ->where('id', $record->id)
                ->update(['response' => $encoded, 'updated_at' => now()]);

            return $result;
        });
    }
}
