<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\InventoryMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function warehouseInventory(Request $request)
    {
        $warehouseId = $request->integer('warehouse_id');
        $query = Warehouse::query()->with(['branch:id,name', 'products:id,name,sku,price,cost']);
        if ($warehouseId) {
            $query->whereKey($warehouseId);
        }

        $warehouses = $query->orderBy('name')->get()->map(function (Warehouse $warehouse) {
            $products = $warehouse->products->map(function ($product) {
                $quantity = (float) ($product->pivot->stock ?? 0);
                $cost = (float) ($product->pivot->cost ?? $product->cost ?? 0);
                $price = (float) ($product->price ?? 0);
                return [
                    'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                    'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => $price,
                    'cost_value' => round($quantity * $cost, 2),
                    'selling_value' => round($quantity * $price, 2),
                    'expected_profit' => round($quantity * ($price - $cost), 2),
                ];
            })->filter(fn (array $product) => $product['quantity'] != 0)->values();

            return [
                'id' => $warehouse->id, 'name' => $warehouse->name,
                'branch' => $warehouse->branch?->only(['id', 'name']), 'products' => $products,
                'totals' => [
                    'quantity' => round($products->sum('quantity'), 3),
                    'cost_value' => round($products->sum('cost_value'), 2),
                    'selling_value' => round($products->sum('selling_value'), 2),
                    'expected_profit' => round($products->sum('expected_profit'), 2),
                ],
            ];
        })->values();

        return response()->json(['data' => $warehouses, 'result' => 'Success']);
    }

    public function warehouseMovements(Request $request)
    {
        $filters = $request->input('filters', []);
        $query = InventoryMovement::query()->with([
            'product:id,name,sku,price,cost', 'warehouse:id,name,branch_id',
            'branch:id,name', 'createdBy:id,name',
        ]);
        foreach (['product_id', 'warehouse_id', 'branch_id', 'movement_type', 'created_by'] as $field) {
            if (!empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (!empty($filters['date_from'])) $query->whereDate('created_at', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('created_at', '<=', $filters['date_to']);
        $analytics = (clone $query)->get()->groupBy(fn (InventoryMovement $movement) => $movement->created_at?->format('Y-m-d') ?? 'unknown')
            ->map(function ($day, $date) {
                $netQuantity = 0.0;
                $addedQuantity = 0.0;
                $removedQuantity = 0.0;
                $costValue = 0.0;
                $sellingValue = 0.0;
                $expectedProfit = 0.0;
                foreach ($day as $movement) {
                    $delta = (float) ($movement->quantity_delta ?? $movement->quantity ?? 0);
                    $cost = (float) ($movement->unit_cost ?? $movement->product?->cost ?? 0);
                    $price = (float) ($movement->product?->price ?? 0);
                    $netQuantity += $delta;
                    $addedQuantity += max($delta, 0);
                    $removedQuantity += abs(min($delta, 0));
                    $costValue += $delta * $cost;
                    $sellingValue += $delta * $price;
                    $expectedProfit += $delta * ($price - $cost);
                }
                return [
                    'date' => $date,
                    'movement_count' => $day->count(),
                    'net_quantity' => round($netQuantity, 3),
                    'added_quantity' => round($addedQuantity, 3),
                    'removed_quantity' => round($removedQuantity, 3),
                    'cost_value' => round($costValue, 2),
                    'selling_value' => round($sellingValue, 2),
                    'expected_profit' => round($expectedProfit, 2),
                ];
            })->sortBy('date')->values();

        $movements = $query->latest('created_at')->latest('id')->paginate(min(max((int) $request->input('perPage', 100), 1), 500));
        $data = collect($movements->items())->map(function (InventoryMovement $movement) {
            $delta = (float) ($movement->quantity_delta ?? $movement->quantity ?? 0);
            $balanceAfter = $movement->balance_after !== null ? (float) $movement->balance_after : null;
            $balanceBefore = $balanceAfter !== null ? $balanceAfter - $delta : null;
            $cost = (float) ($movement->unit_cost ?? $movement->product?->cost ?? 0);
            $price = (float) ($movement->product?->price ?? 0);
            return [
                'id' => $movement->id, 'type' => $movement->movement_type ?? $movement->type ?? 'other',
                'product' => $movement->product?->only(['id', 'name', 'sku']),
                'warehouse' => $movement->warehouse?->only(['id', 'name']),
                'branch' => $movement->branch?->only(['id', 'name']),
                'user' => $movement->createdBy?->only(['id', 'name']),
                'quantity' => abs($delta), 'quantity_delta' => $delta,
                'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
                'unit_cost' => $cost, 'cost_value' => round(abs($delta) * $cost, 2),
                'selling_value' => round(abs($delta) * $price, 2),
                'reference' => $movement->reference_id, 'reference_type' => $movement->reference_type,
                'note' => $movement->notes ?? $movement->note, 'created_at' => $movement->created_at,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'analytics' => $analytics,
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'per_page' => $movements->perPage(), 'total' => $movements->total()],
            'result' => 'Success',
        ]);
    }

    public function inventoryMovements(Request $request)
    {
        $filters = $request->input('filters', []);
        $query = InventoryMovement::query()->with([
            'product:id,name,sku',
            'productUnit:id,product_id,unit_id,barcode',
            'size:id,name',
            'color:id,name',
            'branch:id,name',
            'warehouse:id,name',
            'createdBy:id,name',
            'variantStock:id,identity_key,stock',
        ]);

        $this->applyBranchScope($query, $filters);
        foreach (['product_id', 'product_unit_id', 'size_id', 'color_id', 'branch_id', 'warehouse_id', 'movement_type'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
        }

        $perPage = min(max((int) $request->input('perPage', 25), 1), 200);
        $movements = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $movements->items(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
            'result' => 'Success',
        ]);
    }

    public function shifts(Request $request)
    {
        $filters = $request->input('filters', []);
        $query = CashierShift::query()->with(['employee', 'admin', 'invoices.items.product', 'invoices.salesRepresentative', 'invoices.cashier']);

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('opened_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('opened_at', '<=', $filters['date_to']);
        }

        $user = auth()->user();
        if ($user && $user instanceof \App\Models\Employee && $user->branch_id) {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', $user->branch_id));
        }

        $perPage = min(max((int) $request->input('perPage', 25), 1), 200);
        $shifts = $query->latest('id')->paginate($perPage);

        $shiftData = collect($shifts->items())->map(function (CashierShift $shift) {
            $invoices = $shift->invoices->map(function ($invoice) {
                $cost = (float) $invoice->items->sum(function ($item) {
                    return (float) ($item->quantity ?? 0) * (float) ($item->product?->cost ?? 0);
                });
                $sale = (float) ($invoice->total_amount ?? 0);
                return ['id'=>$invoice->id,'number'=>$invoice->invoice_number,'date'=>$invoice->created_at,'seller'=>$invoice->salesRepresentative?->only(['id','name']),'cashier'=>$invoice->cashier?->only(['id','name']),'sale'=>$sale,'cost'=>round($cost,2),'profit'=>round($sale-$cost,2),'items'=>$invoice->items->map(fn($item)=>['product'=>$item->product?->only(['id','name','sku']),'quantity'=>(float)$item->quantity,'price'=>(float)$item->price,'total'=>(float)$item->total])->values()];
            })->values();
            return array_merge($shift->toArray(), ['invoices'=>$invoices,'invoices_count'=>$invoices->count(),'sales_total'=>(float)$invoices->sum('sale'),'cost_total'=>(float)$invoices->sum('cost'),'profit_total'=>(float)$invoices->sum('profit')]);
        })->values();
        return response()->json([
            'data' => $shiftData,
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
            'result' => 'Success',
        ]);
    }

    private function applyBranchScope($query, array &$filters): void
    {
        $user = auth()->user();
        if ($user && $user instanceof \App\Models\Employee && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
            $filters['branch_id'] = $user->branch_id;
            return;
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
    }
}
