<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManageTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $roleName = strtolower((string) ($user?->role?->name ?? ''));
        $isTenantAdmin = in_array($roleName, ['admin', 'tenant_admin', 'company_admin'], true);

        abort_unless(
            $user && ((bool) ($user->super_admin ?? false) || $isTenantAdmin || $user->hasPermission('roles.manage')),
            403,
            'You do not have permission to manage company access.'
        );

        return $next($request);
    }
}
