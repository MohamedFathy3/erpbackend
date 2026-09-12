<?php
namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user=$request->user() ?: auth('sanctum')->user();

        // Super admins operate at the platform level. Do not require a tenant
        // subdomain/header or attempt to resolve the host slug for them.
        if ($user && (bool) ($user->super_admin ?? false)) {
            return $next($request);
        }

        $host=strtolower($request->getHost());
        $slug=$this->slugFromHost($host, $request);
        if ($slug) {
            $tenant=Tenant::withoutGlobalScopes()->where('slug',$slug)->first();
            if (!$tenant) return response()->json(['message'=>'Tenant workspace was not found.','code'=>'tenant_not_found'],404);
            if ($user && !($user->super_admin ?? false) && (int)$user->tenant_id !== (int)$tenant->id) return response()->json(['message'=>'This account does not belong to the requested workspace.','code'=>'tenant_mismatch'],403);
            app()->instance('currentTenantId',(int)$tenant->id);
            app()->instance('currentTenant',$tenant);
        } elseif ($user && !($user->super_admin ?? false)) {
            return response()->json(['message'=>'Tenant subdomain is required for workspace requests.','code'=>'tenant_subdomain_required'],403);
        }
        return $next($request);
    }
    private function slugFromHost(string $host, Request $request): ?string
    {
        $central=collect(explode(',',(string)config('tenancy.central_domains','')))->map(fn($item)=>trim(strtolower($item)))->filter()->all();
        if (in_array($host,$central,true)) return null;
        $root=strtolower((string)config('tenancy.root_domain','example.com'));
        // The central frontend may serve tenant users on a shared host. In
        // that case the frontend sends the workspace slug explicitly. This
        // is still fail-closed because handle() verifies it against the
        // authenticated user's tenant before setting the current tenant.
        if ($host === $root || !str_ends_with($host,'.'.$root)) {
            $headerSlug = trim((string) $request->header('X-Tenant-Slug', ''));
            return $headerSlug !== '' ? strtolower($headerSlug) : null;
        }
        $prefix=substr($host,0,-(strlen($root)+1));
        return $prefix && !str_contains($prefix,'.') ? $prefix : null;
    }
}
