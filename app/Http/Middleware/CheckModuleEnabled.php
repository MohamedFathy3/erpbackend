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
        if (!$user || ($user->super_admin ?? false)) return $next($request);
        $enabled = TenantModule::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)->where('module_key', $module)
            ->value('is_enabled');
        if (!$enabled) return response()->json(['message' => "The {$module} module is disabled for this tenant."], 403);
        return $next($request);
    }
}
