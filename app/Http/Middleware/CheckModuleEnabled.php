<?php

namespace App\Http\Middleware;

use App\Models\TenantModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ((bool) ($user->super_admin ?? false)) {
            return $next($request);
        }
        if (!$user->tenant_id) {
            return response()->json(['message' => 'Tenant is not configured.'], 403);
        }
        $enabled = TenantModule::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('module_key', $module)
            ->where('is_enabled', true)
            ->exists();
        if (!$enabled) {
            return response()->json(['message' => 'This module is disabled for your tenant.'], 403);
        }
        return $next($request);
    }
}
