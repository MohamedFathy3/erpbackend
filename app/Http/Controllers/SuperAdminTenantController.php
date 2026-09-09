<?php
namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SuperAdminTenantController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user() && ($request->user()->super_admin ?? false), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        return response()->json(['data' => Tenant::withoutGlobalScopes()->withCount('admins')->latest()->get()]);
    }

    public function modules(Request $request, Tenant $tenant)
    {
        $this->guard($request);
        $items = collect(TenantModule::available())->map(function ($key) use ($tenant) {
            $row = TenantModule::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_key' => $key], ['is_enabled' => true]
            );
            return ['module_key' => $key, 'is_enabled' => (bool) $row->is_enabled];
        });
        return response()->json(['data' => $items->values()]);
    }

    public function updateModule(Request $request, Tenant $tenant, string $moduleKey)
    {
        $this->guard($request);
        abort_unless(in_array($moduleKey, TenantModule::available(), true), 422, 'Unknown module.');
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $module = TenantModule::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => $moduleKey], $data
        );
        activity()->causedBy($request->user())->performedOn($module)->withProperties($data)->log('tenant module toggled');
        return response()->json(['data' => ['module_key' => $moduleKey, 'is_enabled' => (bool) $module->is_enabled]]);
    }

    public function enabledModules(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->super_admin ?? false) return response()->json(['data' => TenantModule::available()]);
        return response()->json(['data' => TenantModule::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('is_enabled', true)->pluck('module_key')->values()]);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $request->validate(['name' => ['required','string','max:255'], 'slug' => ['nullable','string','max:100','unique:tenants,slug'], 'status' => ['nullable','in:active,suspended,trial'], 'plan' => ['nullable','string','max:100']]);
        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::withoutGlobalScopes()->create(['name' => $data['name'], 'slug' => $data['slug'] ?? Str::slug($data['name']), 'status' => $data['status'] ?? 'trial', 'plan' => $data['plan'] ?? 'starter']);
            foreach (TenantModule::available() as $key) TenantModule::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'module_key' => $key, 'is_enabled' => true]);
            return $tenant;
        });
        return response()->json(['data' => $tenant], 201);
    }
}
