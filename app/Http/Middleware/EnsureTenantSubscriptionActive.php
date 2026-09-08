<?php
namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user=$request->user();
        if (!$user || ($user->super_admin ?? false)) return $next($request);
        $tenant=method_exists($user,'tenant') ? $user->tenant : Tenant::withoutGlobalScopes()->find($user->tenant_id ?? null);
        if (!$tenant) return response()->json(['message'=>'Tenant subscription is unavailable.'], 403);
        if ($tenant->subscription_status === 'suspended') return response()->json(['message'=>'Your workspace is suspended. Please contact support.','code'=>'tenant_suspended'], 403);
        if ($tenant->subscription_status === 'trial' && $tenant->trial_ends_at?->isPast()) return response()->json(['message'=>'Your free trial has ended. Please subscribe to continue.','code'=>'trial_expired'], 402);
        if ($tenant->subscription_status === 'expired') return response()->json(['message'=>'Your subscription has expired. Please subscribe to continue.','code'=>'subscription_expired'], 402);
        return $next($request);
    }
}
