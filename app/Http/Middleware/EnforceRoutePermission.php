<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: auth('sanctum')->user();
        // Admin accounts are the tenant owners. AdminResource exposes role=admin,
        // but the role relation may not be loaded (or may be null) on the token user.
        $isAdmin = $user instanceof Admin
            || ($user && str_contains(strtolower((string) $user->role?->name), 'admin'));
        // A user may have only direct permissions and no role. Do not bypass
        // authorization in that case; hasPermission() checks both sources.
        if (!$user || (bool) ($user->super_admin ?? false) || $isAdmin) {
            return $next($request);
        }

        $permission = $this->permissionFor($request);
        if (!$permission) {
            return $next($request);
        }

        $identifier = Permission::identifierColumn();
        $candidatePermissions = [$permission];
        // Keep existing finance roles working while allowing the new
        // tax/treasury/currency/bank permissions to be assigned separately.
        if (in_array($permission, [
            'tax.view', 'tax.create', 'tax.update', 'tax.delete',
            'treasury.view', 'treasury.create', 'treasury.update', 'treasury.delete',
            'currency.view', 'currency.create', 'currency.update', 'currency.delete',
            'bank.view', 'bank.create', 'bank.update', 'bank.delete',
        ], true)) {
            $candidatePermissions[] = 'finance.' . substr($permission, strpos($permission, '.') + 1);
        }
        $exists = Permission::query()->whereIn($identifier, $candidatePermissions)->exists();
        if (!$exists) {
            return $next($request);
        }

        abort_unless(
            collect($candidatePermissions)->contains(fn (string $candidate): bool => $user->hasPermission($candidate)),
            403,
            'You do not have permission to perform this action.'
        );
        return $next($request);
    }

    private function permissionFor(Request $request): ?string
    {
        $path = preg_replace('#^api/#', '', trim($request->path(), '/')) ?? trim($request->path(), '/');
        $segments = explode('/', $path);
        $resource = $segments[0] ?? '';
        if ($resource === 'inventory-transfer-requests') {
            if (($segments[1] ?? '') === 'products') return 'inventory.transfer_requests.view';
            if (($segments[2] ?? '') === 'approve' || ($segments[2] ?? '') === 'reject') return 'inventory.transfer_requests.approve';
            return strtoupper($request->method()) === 'GET'
                ? 'inventory.transfer_requests.view'
                : 'inventory.transfer_requests.create';
        }
        $module = [
            'admin' => 'users', 'user' => 'users', 'employee' => 'hr', 'employees' => 'hr',
            'product' => 'inventory', 'products' => 'inventory', 'warehouse' => 'inventory',
            'warehouse-stock' => 'inventory', 'offer' => 'inventory', 'category' => 'inventory',
            'customer' => 'crm', 'customers' => 'crm', 'supplier' => 'purchasing',
            'purchase' => 'purchasing', 'purchases' => 'purchasing',
            'invoice' => 'sales', 'invoices' => 'sales', 'sales' => 'sales', 'pos' => 'sales',
            'currency' => 'currency', 'tax' => 'tax', 'bank' => 'bank', 'treasury' => 'treasury',
            'reports' => 'reports', 'project' => 'projects', 'projects' => 'projects',
            'manufacturing' => 'manufacturing', 'access-control' => 'access_control',
            'ai' => 'ai_assistant', 'notifications' => 'notifications',
        ][$resource] ?? null;

        if (!$module || in_array($resource, ['login', 'auth', 'get-admin'], true)) {
            return null;
        }

        // These endpoints use POST to fetch a paginated list; they still
        // require the read permission, not create permission.
        if (($segments[1] ?? '') === 'index') {
            return "{$module}.view";
        }

        $action = match (strtoupper($request->method())) {
            'GET', 'HEAD' => 'view',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => null,
        };

        return $action ? "{$module}.{$action}" : null;
    }
}
