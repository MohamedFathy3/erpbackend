<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles')) return;

        $permissionColumn = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $permissionId = DB::table('permissions')->where($permissionColumn, 'crm.send_email')->value('id');
        if (!$permissionId) return;

        $pivot = Schema::hasTable('role_permissions') ? 'role_permissions'
            : (Schema::hasTable('permission_role') ? 'permission_role' : null);
        if (!$pivot) return;

        foreach (DB::table('roles')->whereIn(DB::raw('LOWER(name)'), ['admin', 'tenant_admin', 'company_admin'])->pluck('id') as $roleId) {
            $values = ['role_id' => $roleId, 'permission_id' => $permissionId];
            if (Schema::hasColumn($pivot, 'created_at')) {
                $values['created_at'] = now();
                $values['updated_at'] = now();
            }
            DB::table($pivot)->insertOrIgnore($values);
        }
    }

    public function down(): void
    {
        // Keep permissions assigned to tenant admins on rollback; the middleware remains safe.
    }
};
