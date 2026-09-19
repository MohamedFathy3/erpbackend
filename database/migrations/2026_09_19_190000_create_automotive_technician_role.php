<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('tenants')) return;
        $now = now();
        $roleColumn = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $permissionKeys = ['automotive.view', 'automotive.portal.view', 'automotive.portal.technician_login', 'automotive.orders.view', 'automotive.orders.update', 'automotive.photos.upload'];
        $permissionIds = Schema::hasTable('permissions')
            ? DB::table('permissions')->whereIn($roleColumn, $permissionKeys)->pluck('id')
            : collect();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $role = DB::table('roles')->where('tenant_id', $tenantId)->whereRaw('LOWER(name) = ?', ['technician'])->first();
            if (!$role) {
                $roleId = DB::table('roles')->insertGetId(['tenant_id' => $tenantId, 'name' => 'technician', 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $roleId = $role->id;
            }
            $pivot = Schema::hasTable('role_permissions') ? 'role_permissions' : (Schema::hasTable('permission_role') ? 'permission_role' : null);
            if ($pivot) foreach ($permissionIds as $permissionId) DB::table($pivot)->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('roles')) return;
        DB::table('roles')->whereRaw('LOWER(name) = ?', ['technician'])->delete();
    }
};
