<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Http\Request;

class SuperAdminTenantController extends Controller
{
    private const MODULES = [
        'crm', 'email', 'whatsapp', 'google_calendar', 'google_drive', 'tasks',
        'manufacturing', 'inventory', 'sales', 'purchasing', 'finance', 'hr',
        'reports', 'projects', 'workflow',
    ];

    public function tenants()
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        return response()->json([
            'data' => Tenant::query()->withCount('modules')->withCount('admins')->latest()->get(),
        ]);
    }

    public function modules(Tenant $tenant)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        $modules = collect(self::MODULES)->map(function (string $key) use ($tenant): array {
            $record = TenantModule::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('module_key', $key)
                ->first();
            return ['module_key' => $key, 'is_enabled' => $record?->is_enabled ?? true];
        });
        return response()->json(['data' => $modules]);
    }

    public function updateModule(Request $request, Tenant $tenant, string $moduleKey)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);
        abort_unless(in_array($moduleKey, self::MODULES, true), 404);
        $module = TenantModule::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => $moduleKey],
            ['is_enabled' => $validated['is_enabled']],
        );
        activity()->performedOn($module)->withProperties($validated)->log('tenant module updated');
        return response()->json(['data' => $module]);
    }

    public function enabledModules()
    {
        $user = auth()->user();
        abort_unless($user, 401);
        if ((bool) ($user->super_admin ?? false)) {
            return response()->json(['data' => self::MODULES]);
        }
        return response()->json([
            'data' => TenantModule::query()->where('is_enabled', true)->pluck('module_key')->values(),
        ]);
    }
}
