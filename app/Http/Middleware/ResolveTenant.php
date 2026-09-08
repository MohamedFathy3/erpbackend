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
        $host=strtolower($request->getHost());
        $slug=$this->slugFromHost($host, $request);
        $user=$request->user();
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
        if ($host === $root || !str_ends_with($host,'.'.$root)) return app()->environment('local') && $request->header('X-Tenant-Slug') ? strtolower((string)$request->header('X-Tenant-Slug')) : null;
        $prefix=substr($host,0,-(strlen($root)+1));
        return $prefix && !str_contains($prefix,'.') ? $prefix : null;
    }
}
