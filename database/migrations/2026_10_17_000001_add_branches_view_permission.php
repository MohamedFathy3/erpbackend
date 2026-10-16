<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $permissionKey = 'branches.view';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $values = [$column => $this->permissionKey];
        if (Schema::hasColumn('permissions', 'key')) $values['key'] = $this->permissionKey;
        if (Schema::hasColumn('permissions', 'slug')) $values['slug'] = $this->permissionKey;
        if (Schema::hasColumn('permissions', 'name')) $values['name'] = 'View All Tenant Branches';
        if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = 'عرض فروع المنشأة';
        if (Schema::hasColumn('permissions', 'module')) $values['module'] = 'branches';
        if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = now();
        if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = now();

        DB::table('permissions')->updateOrInsert([$column => $this->permissionKey], $values);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $permissionId = DB::table('permissions')->where($column, $this->permissionKey)->value('id');
        if ($permissionId) {
            foreach (['role_permissions', 'employee_permissions'] as $pivot) {
                if (Schema::hasTable($pivot) && Schema::hasColumn($pivot, 'permission_id')) {
                    DB::table($pivot)->where('permission_id', $permissionId)->delete();
                }
            }
        }
        DB::table('permissions')->where($column, $this->permissionKey)->delete();
    }
};
