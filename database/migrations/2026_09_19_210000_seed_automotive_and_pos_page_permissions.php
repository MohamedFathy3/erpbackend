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
        $keys = [
            'automotive.dashboard.view',
            'automotive.dashboard.create',
            'automotive.dashboard.update',
            'automotive.dashboard.delete',
            'sales.pos_return.view',
            'sales.pos_return.create',
            'sales.pos_return.update',
            'sales.pos_return.delete',
        ];
        foreach ($keys as $key) {
            $values = [$column => $key];
            if (Schema::hasColumn('permissions', 'key')) $values['key'] = $key;
            if (Schema::hasColumn('permissions', 'slug')) $values['slug'] = $key;
            if (Schema::hasColumn('permissions', 'name')) $values['name'] = ucwords(str_replace(['.', '_'], ' ', $key));
            if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = 'صلاحية ' . str_replace(['.', '_'], ' ', $key);
            if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = now();
            if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = now();
            DB::table('permissions')->updateOrInsert([$column => $key], $values);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        DB::table('permissions')->whereIn($column, [
            'sales.pos_return.view', 'sales.pos_return.create', 'sales.pos_return.update', 'sales.pos_return.delete',
        ])->delete();
    }
};
