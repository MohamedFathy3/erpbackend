<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BranchScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof Employee || !$user->branch_id) {
            return $next($request);
        }

        $branchId = (int) $user->branch_id;
        $requestedBranchId = (int) ($request->input('branch_id') ?: $request->input('filters.branch_id'));
        if ($requestedBranchId && $requestedBranchId !== $branchId) {
            return response()->json(['message' => 'You are not allowed to access another branch'], 403);
        }

        foreach (['warehouse_id', 'from_warehouse_id', 'to_warehouse_id'] as $field) {
            $warehouseId = $request->input($field);
            if ($warehouseId && !DB::table('warehouses')->where('id', $warehouseId)->where('branch_id', $branchId)->exists()) {
                return response()->json(['message' => 'Warehouse does not belong to your branch'], 403);
            }
        }

        $request->merge(['branch_id' => $branchId]);
        $filters = $request->input('filters');
        if (is_array($filters)) {
            $filters['branch_id'] = $branchId;
            $request->merge(['filters' => $filters]);
        }

        return $next($request);
    }
}
