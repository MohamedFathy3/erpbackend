<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) return;

        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        $values = [$column => 'employee_financial_reports.view'];
        if (Schema::hasColumn('permissions', 'slug')) $values['slug'] = 'employee_financial_reports.view';
        if (Schema::hasColumn('permissions', 'name')) $values['name'] = 'View Employee Financial Reports';
        if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = 'عرض التقارير المالية للموظفين';
        if (Schema::hasColumn('permissions', 'module')) $values['module'] = 'hr';
        if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = now();
        if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = now();

        DB::table('permissions')->updateOrInsert([$column => 'employee_financial_reports.view'], $values);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        DB::table('permissions')->where($column, 'employee_financial_reports.view')->delete();
    }
};
