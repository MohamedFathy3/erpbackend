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
        if ($response = $this->apply($request, $user)) return $response;
        return $next($request);
    }

    public function apply(Request $request, $user): ?Response
    {
        if (!$user) return null;

        // Tenant admins must be able to move an employee to another branch
        // in the same workspace. Do not overwrite the submitted branch_id on
        // employee-management requests; EmployeeRequest validates the target
        // branch against the authenticated tenant.
        $path = trim($request->path(), '/');
        $role = strtolower((string) ($user->role?->name ?? $user->role ?? ''));
        $isTenantAdmin = (bool) ($user->super_admin ?? false)
            || in_array($role, ['admin', 'administrator', 'tenant_admin', 'company_admin'], true);
        if ($isTenantAdmin && preg_match('#(^|/)employee(?:/|$)#', $path)) {
            return null;
        }

        $requestedBranchId = (int) ($request->input('branch_id') ?: $request->input('filters.branch_id'));
        $assignedBranchId = (int) ($user->branch_id ?? 0);
        if ($assignedBranchId && $requestedBranchId && $requestedBranchId !== $assignedBranchId) {
            return response()->json(['message' => 'You are not allowed to access another branch'], 403);
        }
        $branchId = $assignedBranchId ?: $requestedBranchId;
        if (!$branchId) return null;
        if (!$assignedBranchId && !($user->super_admin ?? false) && !DB::table('branches')->where('id', $branchId)->where('tenant_id', $user->tenant_id)->exists()) {
            return response()->json(['message' => 'Branch does not belong to your workspace'], 403);
        }

        foreach (['warehouse_id', 'from_warehouse_id', 'to_warehouse_id'] as $field) {
            $warehouseId = $request->input($field);
            if ($warehouseId && !
           DB::table('warehouses')
    ->where('id', $warehouseId)
    ->where('branch_id', $branchId)
    ->where('tenant_id', $user->tenant_id)
    ->exists())
             {
                return response()->json(['message' => 'Warehouse does not belong to your branch'], 403);
            }
        }

        $request->merge(['branch_id' => $branchId]);
        app()->instance('currentBranchId', $branchId);
        $filters = $request->input('filters');
        if (is_array($filters)) {
            $filters['branch_id'] = $branchId;
            $request->merge(['filters' => $filters]);
        }

        return null;
    }
}
