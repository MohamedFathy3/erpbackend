<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;

class ReportController extends Controller
{
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
        ]);

        $this->applyBranchScope($query, $filters);
        foreach (['product_id', 'product_unit_id', 'size_id', 'color_id', 'warehouse_id', 'movement_type'] as $field) {
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
        $query = CashierShift::query()->with(['employee', 'admin']);

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

        return response()->json([
            'data' => $shifts->items(),
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
