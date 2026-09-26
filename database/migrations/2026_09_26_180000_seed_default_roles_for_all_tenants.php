<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('tenants') || !Schema::hasTable('roles')) return;
        $names = ['Admin', 'Manager', 'Accountant', 'Sales', 'Purchasing', 'Warehouse', 'HR', 'Cashier', 'Viewer'];
        $permissionIds = Schema::hasTable('permissions') ? DB::table('permissions')->pluck('id') : collect();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($names as $name) {
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('name', $name)->value('id');
                if (!$roleId) $roleId = DB::table('roles')->insertGetId(['tenant_id' => $tenantId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                if ($name === 'Admin' && Schema::hasTable('role_permissions') && $permissionIds->isNotEmpty()) foreach ($permissionIds as $permissionId) DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }
    public function down(): void {}
};
