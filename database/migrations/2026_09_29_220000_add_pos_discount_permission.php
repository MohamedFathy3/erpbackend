<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $permissionKey = 'sales.pos_discount.apply';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $identifier = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $values = [$identifier => $this->permissionKey];
        if (Schema::hasColumn('permissions', 'key')) $values['key'] = $this->permissionKey;
        if (Schema::hasColumn('permissions', 'slug')) $values['slug'] = $this->permissionKey;
        if (Schema::hasColumn('permissions', 'name')) $values['name'] = 'Apply POS Discounts';
        if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = 'تطبيق خصم نقاط البيع';
        if (Schema::hasColumn('permissions', 'module')) $values['module'] = 'sales';
        if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = now();
        if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = now();

        DB::table('permissions')->updateOrInsert([$identifier => $this->permissionKey], $values);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $identifier = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $permissionId = DB::table('permissions')->where($identifier, $this->permissionKey)->value('id');
        if (!$permissionId) {
            return;
        }

        foreach (['employee_permissions', 'role_permissions', 'permission_role'] as $pivot) {
            if (Schema::hasTable($pivot)) {
                DB::table($pivot)->where('permission_id', $permissionId)->delete();
            }
        }

        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
