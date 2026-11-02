<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $identifier = Schema::hasColumn('permissions', 'key')
            ? 'key'
            : (Schema::hasColumn('permissions', 'slug') ? 'slug' : null);
        if (!$identifier) return;

        $values = [
            $identifier => 'all_branch',
            'name' => 'View All Branches',
            'name_ar' => 'عرض كل الفروع',
            'module' => 'branches',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('permissions');
        DB::table('permissions')->updateOrInsert(
            [$identifier => 'all_branch'],
            array_intersect_key($values, array_flip($columns))
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $identifier = Schema::hasColumn('permissions', 'key')
            ? 'key'
            : (Schema::hasColumn('permissions', 'slug') ? 'slug' : null);
        if ($identifier) DB::table('permissions')->where($identifier, 'all_branch')->delete();
    }
};
