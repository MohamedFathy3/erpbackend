<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminTenantController extends Controller
{
    private const MODULES = [
        'crm', 'email', 'whatsapp', 'google_calendar', 'google_drive', 'tasks',
        'manufacturing', 'inventory', 'sales', 'purchasing', 'finance', 'hr',
        'reports', 'projects', 'workflow',
    ];

    public function index()
    {
        return $this->tenants();
    }

    public function store(Request $request)
    {
        abort_unless((bool) auth()->user()?->super_admin, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:tenants,slug'],
            'status' => ['nullable', 'in:active,suspended,trial,expired'],
            'plan' => ['nullable', 'string', 'max:50'],
            'trial_ends_at' => ['nullable', 'date'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'unique:admins,email'],
            'admin_password' => ['nullable', 'string', 'min:8'],
        ]);

        if (($data['admin_email'] ?? null) && empty($data['admin_password'])) {
            return response()->json(['message' => 'admin_password is required when admin_email is provided.'], 422);
        }

        $result = DB::transaction(function () use ($data): array {
            $slug = $data['slug'] ?? Str::slug($data['name']);
            if ($slug === '') $slug = 'workspace-' . Str::lower(Str::random(6));

            $tenant = Tenant::withoutGlobalScopes()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'status' => $data['status'] ?? 'active',
                'plan' => $data['plan'] ?? 'starter',
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'subscription_status' => ($data['status'] ?? 'active') === 'trial' ? 'trial' : 'active',
            ]);

            foreach (self::MODULES as $moduleKey) {
                TenantModule::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'module_key' => $moduleKey,
                    'is_enabled' => true,
                ]);
            }

            $admin = null;
            if (!empty($data['admin_email'])) {
                $admin = Admin::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['admin_name'] ?? $data['admin_email'],
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password']),
                    'active' => true,
                    'super_admin' => false,
                ]);
            }

            return [$tenant, $admin];
        });

        [$tenant, $admin] = $result;
        return response()->json([
            'data' => [
                'tenant' => $tenant,
                'admin' => $admin,
            ],
        ], 201);
    }

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
