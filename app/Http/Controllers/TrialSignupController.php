<?php
namespace App\Http\Controllers;

use App\Jobs\SendTrialEmail;
use App\Models\Admin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TrialSignupController extends Controller
{
    public function store(Request $request)
    {
        $data=$request->validate(['company_name'=>'required|string|max:255','full_name'=>'required|string|max:255','email'=>'required|email|max:255|unique:admins,email','phone'=>'nullable|string|max:50','password'=>'required|string|min:8|confirmed']);
        $tenant=DB::transaction(function () use ($data) {
            $starts=now(); $ends=now()->addDays(15); $slug=$this->uniqueSlug($data['company_name']);
            $tenant=Tenant::withoutGlobalScopes()->create(['name'=>$data['company_name'],'slug'=>$slug,'status'=>'trial','subscription_status'=>'trial','plan'=>'trial','trial_starts_at'=>$starts,'trial_ends_at'=>$ends]);
            foreach (TenantModule::available() as $key) TenantModule::withoutGlobalScopes()->create(['tenant_id'=>$tenant->id,'module_key'=>$key,'is_enabled'=>true]);
            $roles = $this->seedTenantRoles($tenant->id);
            $role = $roles['Admin'];
            $admin=Admin::withoutGlobalScopes()->create(['tenant_id'=>$tenant->id,'role_id'=>$role?->id,'name'=>$data['full_name'],'email'=>$data['email'],'phone'=>$data['phone'] ?? null,'password'=>Hash::make($data['password']),'active'=>true,'super_admin'=>false]);
            return [$tenant,$admin];
        });
        [$tenant,$admin]=$tenant;
        $admin->sendEmailVerificationNotification();
        $loginUrl=$this->tenantUrl($tenant->slug);
        SendTrialEmail::dispatch($admin->email, 'Welcome to your 15-day ERP trial', "<h2>Welcome {$admin->name}</h2><p>Your workspace <strong>{$tenant->name}</strong> is ready.</p><p>Your free trial ends on {$tenant->trial_ends_at->toDateString()}.</p><p><a href=\"{$loginUrl}\">Login to your workspace</a></p>");
        return response()->json(['message'=>'Trial workspace created successfully.','data'=>['tenant'=>$tenant->only(['id','name','slug','trial_starts_at','trial_ends_at','subscription_status']),'login_url'=>$loginUrl]],201);
    }

    private function seedTenantRoles(int $tenantId): array
    {
        $names = ['Admin', 'Manager', 'Accountant', 'Sales', 'Purchasing', 'Warehouse', 'HR', 'Cashier', 'Viewer'];
        $roles = [];
        foreach ($names as $name) {
            $role = Role::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['created_at' => now(), 'updated_at' => now()]);
            $roles[$name] = $role;
        }
        if (Schema::hasTable('role_permissions') && Schema::hasTable('permissions')) {
            $all = Permission::withoutGlobalScopes()->pluck('id');
            foreach ($all as $permissionId) DB::table('role_permissions')->insertOrIgnore(['role_id' => $roles['Admin']->id, 'permission_id' => $permissionId]);
        }
        return $roles;
    }
    private function uniqueSlug(string $name): string { $base=Str::slug($name) ?: 'workspace'; $slug=$base; $i=1; while (Tenant::withoutGlobalScopes()->where('slug',$slug)->exists()) $slug=$base.'-'.(++$i); return $slug; }
    private function tenantUrl(string $slug): string
    {
        $frontendUrl = (string) config('app.frontend_url', config('app.url'));
        $scheme = parse_url($frontendUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . '://' . $slug . '.' . config('tenancy.root_domain') . '/auth';
    }
}
