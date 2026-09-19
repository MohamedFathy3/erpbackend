<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles')) return;
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $permissionIds = DB::table('permissions')->whereIn($column, ['automotive.view', 'automotive.dashboard.view'])->pluck('id');
        if ($permissionIds->isEmpty()) return;
        $pivot = Schema::hasTable('role_permissions') ? 'role_permissions' : (Schema::hasTable('permission_role') ? 'permission_role' : null);
        if (!$pivot) return;
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table($pivot)->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $ids = DB::table('permissions')->whereIn($column, ['automotive.view', 'automotive.dashboard.view'])->pluck('id');
        $pivot = Schema::hasTable('role_permissions') ? 'role_permissions' : (Schema::hasTable('permission_role') ? 'permission_role' : null);
        if ($pivot && $ids->isNotEmpty()) DB::table($pivot)->whereIn('permission_id', $ids)->delete();
    }
};
