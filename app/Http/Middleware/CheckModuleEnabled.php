<?php

namespace App\Http\Middleware;

use App\Models\TenantModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $module ??= $this->moduleForPath($request->path());
        if (!$module) {
            return $next($request);
        }

        $user = $request->user() ?: auth('sanctum')->user();
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

    private function moduleForPath(string $path): ?string
    {
        $path = preg_replace('#^api/#', '', trim($path, '/')) ?? trim($path, '/');
        $modules = [
            'crm' => ['crm', 'customer', 'customers'],
            'email' => [],
            'whatsapp' => ['whatsapp'],
            'google_calendar' => ['calendar', 'google-integrations'],
            'tasks' => ['tasks'],
            'manufacturing' => ['manufacturing'],
            'inventory' => ['inventory', 'inventor', 'warehouse', 'warehouses', 'product', 'products', 'category', 'color', 'unit', 'offer', 'product-ledger'],
            'sales' => ['pos', 'sales', 'invoice', 'invoices', 'sales-invoice', 'sales-invoices', 'sales-return', 'return-invoice', 'sales-representative', 'delevery-man'],
            'purchasing' => ['supplier', 'suppliers', 'purchase', 'purchases'],
            'finance' => ['finance', 'bank', 'revenue', 'currency', 'tax', 'accounts', 'journal'],
            'hr' => ['employee', 'employees', 'attendance'],
            'reports' => ['reports'],
            'projects' => ['projects'],
            'workflow' => ['workflow'],
        ];

        foreach ($modules as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return $module;
                }
            }
        }

        return null;
    }
}
