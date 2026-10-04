<?php
namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Http\Middleware\BranchScope;
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
        // Reverse proxies and shared API hosts can differ from the tenant's
        // canonical slug (for example protect-plus-2.* serving tenant acsa).
        // An explicit workspace header takes precedence, but the authenticated
        // account is still checked against the resolved tenant below.
        $headerSlug = trim((string) $request->header('X-Tenant-Slug', ''));
        $slug = $headerSlug !== ''
            ? strtolower($headerSlug)
            : $this->slugFromHost($host, $request);
        if ($slug) {
            $tenant=Tenant::withoutGlobalScopes()->where('slug',$slug)->first();
            if (!$tenant) return response()->json(['message'=>'Tenant workspace was not found.','code'=>'tenant_not_found'],404);
            if ($user && !($user->super_admin ?? false) && (int)$user->tenant_id !== (int)$tenant->id) return response()->json(['message'=>'This account does not belong to the requested workspace.','code'=>'tenant_mismatch'],403);
            app()->instance('currentTenantId',(int)$tenant->id);
            app()->instance('currentTenant',$tenant);
        } elseif ($user && !($user->super_admin ?? false) && $user->tenant_id) {
            // Authenticated tenant users already carry their workspace in the
            // account. Use it as a safe fallback for same-origin/API requests
            // where the browser did not send the subdomain header.
            $tenant=Tenant::withoutGlobalScopes()->find($user->tenant_id);
            if (!$tenant) return response()->json(['message'=>'Tenant workspace was not found.','code'=>'tenant_not_found'],404);
            app()->instance('currentTenantId',(int)$tenant->id);
            app()->instance('currentTenant',$tenant);
        } elseif ($user && !($user->super_admin ?? false)) {
            return response()->json(['message'=>'Tenant subdomain is required for workspace requests.','code'=>'tenant_subdomain_required'],403);
        }
        // Apply the branch boundary after tenant resolution for every
        // authenticated tenant endpoint, not only legacy route groups that
        // explicitly listed branch.scope.
        if ($user) {
            if ($response = app(BranchScope::class)->apply($request, $user)) return $response;
        }
        return $next($request);
    }
    private function slugFromHost(string $host, Request $request): ?string
    {
        $central=collect(explode(',',(string)config('tenancy.central_domains','')))->map(fn($item)=>trim(strtolower($item)))->filter()->all();
        if (in_array($host,$central,true)) return null;
        $root=strtolower((string)config('tenancy.root_domain','example.com'));
        // Shared/central hosts do not imply a tenant. Authenticated users may
        // fall back to their assigned tenant_id in handle().
        if ($host === $root || !str_ends_with($host,'.'.$root)) {
            return null;
        }
        $prefix=substr($host,0,-(strlen($root)+1));
        return $prefix && !str_contains($prefix,'.') ? $prefix : null;
    }
}
