<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) return;

        $modules = [
            'users', 'workflow', 'whatsapp', 'google_calendar', 'tasks',
            'industries', 'product_ledger', 'representative', 'integrations',
        ];
        $actions = ['view', 'create', 'update', 'delete'];
        $now = now();
        $hasKey = Schema::hasColumn('permissions', 'key');
        $hasSlug = Schema::hasColumn('permissions', 'slug');
        $hasName = Schema::hasColumn('permissions', 'name');
        $hasNameAr = Schema::hasColumn('permissions', 'name_ar');
        $hasModule = Schema::hasColumn('permissions', 'module');

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                $key = "{$module}.{$action}";
                $where = $hasKey ? ['key' => $key] : ['slug' => $key];
                $values = [];
                if ($hasKey) $values['key'] = $key;
                if ($hasSlug) $values['slug'] = $key;
                if ($hasName) $values['name'] = ucfirst($action) . ' ' . ucwords(str_replace('_', ' ', $module));
                if ($hasNameAr) $values['name_ar'] = ucfirst($action) . ' ' . ucwords(str_replace('_', ' ', $module));
                if ($hasModule) $values['module'] = $module;
                if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = $now;
                if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = $now;
                DB::table('permissions')->updateOrInsert($where, $values);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $modules = ['users', 'workflow', 'whatsapp', 'google_calendar', 'tasks', 'industries', 'product_ledger', 'representative', 'integrations'];
        $keys = [];
        foreach ($modules as $module) foreach (['view', 'create', 'update', 'delete'] as $action) $keys[] = "{$module}.{$action}";
        DB::table('permissions')->whereIn(Schema::hasColumn('permissions', 'key') ? 'key' : 'slug', $keys)->delete();
    }
};
