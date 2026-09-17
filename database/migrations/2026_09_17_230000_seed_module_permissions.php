<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $keys = [];
        $modules = [
            'dashboard', 'inventory', 'sales', 'purchasing', 'finance', 'hr', 'crm',
            'reports', 'projects', 'manufacturing', 'access_control', 'ai_assistant',
            'notifications',
        ];
        foreach ($modules as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $keys[] = [$module . '.' . $action, ucfirst($action) . ' ' . ucfirst(str_replace('_', ' ', $module)), $module];
            }
        }

        $now = now();
        $hasKey = Schema::hasColumn('permissions', 'key');
        foreach ($keys as [$key, $name, $module]) {
            $where = $hasKey ? ['key' => $key] : ['slug' => $key];
            DB::table('permissions')->updateOrInsert(
                $where,
                $hasKey
                    ? ['name' => $name, 'name_ar' => $name, 'module' => $module, 'created_at' => $now, 'updated_at' => $now]
                    : ['name' => $name, 'slug' => $key, 'parent_id' => null, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $modules = ['dashboard', 'inventory', 'sales', 'purchasing', 'finance', 'hr', 'crm', 'reports', 'projects', 'manufacturing', 'access_control', 'ai_assistant', 'notifications'];
        $keys = collect($modules)->flatMap(fn (string $module) => collect(['view', 'create', 'update', 'delete'])->map(fn (string $action) => $module . '.' . $action));
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        DB::table('permissions')->whereIn($column, $keys->all())->delete();
    }
};
