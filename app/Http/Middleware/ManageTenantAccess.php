<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManageTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $roleName = strtolower((string) ($user?->role?->name ?? ''));
        $isTenantAdmin = $user instanceof Admin
            || $roleName === 'admin';

        abort_unless(
            $user && ((bool) ($user->super_admin ?? false) || $isTenantAdmin),
            403,
            'You do not have permission to manage company access.'
        );

        return $next($request);
    }
}
