<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $values = [
            $column => 'purchases-invoices.update',
        ];

        if (Schema::hasColumn('permissions', 'name')) $values['name'] = 'Update Purchase Invoices';
        if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = 'تعديل فواتير المشتريات المرحّلة';
        if (Schema::hasColumn('permissions', 'module')) $values['module'] = 'purchasing';
        if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = now();
        if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = now();

        DB::table('permissions')->updateOrInsert([$column => $values[$column]], $values);
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where(Schema::hasColumn('permissions', 'key') ? 'key' : 'slug', 'purchases-invoices.update')
                ->delete();
        }
    }
};
