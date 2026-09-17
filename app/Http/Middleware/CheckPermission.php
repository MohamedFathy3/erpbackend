<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $roleName = strtolower((string) ($user?->role?->name ?? ''));
        $tenantAdmin = in_array($roleName, ['admin', 'tenant_admin', 'company_admin'], true);
        $allowed = $user && ((bool) ($user->super_admin ?? false)
            || $user->hasPermission($permission)
            || ($tenantAdmin && $permission === 'crm.send_email'));

        abort_unless($allowed, 403, 'You do not have permission to perform this action.');
        return $next($request);
    }
}
