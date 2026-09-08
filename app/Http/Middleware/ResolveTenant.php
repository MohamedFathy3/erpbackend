<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && !($user->super_admin ?? false) && empty($user->tenant_id)) {
            return response()->json(['message' => 'Your account is not assigned to a tenant.'], 403);
        }
        if ($user && !empty($user->tenant_id)) {
            app()->instance('currentTenantId', (int) $user->tenant_id);
        }
        return $next($request);
    }
}
