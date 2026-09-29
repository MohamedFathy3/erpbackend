<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBiometricAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = app()->bound('currentTenantId') ? (int) app('currentTenantId') : 0;
        $token = $request->bearerToken();
        if (!$tenantId || !$token || strlen($token) !== 64) {
            abort(401, 'A valid paired biometric-agent bearer token is required.');
        }

        // Query the token hash directly to avoid Sanctum resolving an Admin model
        // while BaseModel's global scope is itself asking the auth guard for a user.
        $agent = DB::table('biometric_agents')
            ->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->whereNull('deleted_at')
            ->first(['id']);

        if (!$agent) {
            abort(401, 'The biometric-agent token is invalid or has been revoked.');
        }

        $request->attributes->set('biometricAgentId', (int) $agent->id);

        return $next($request);
    }
}
