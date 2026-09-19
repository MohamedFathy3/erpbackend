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

        $entities = [
            'tax' => ['Tax', 'الضرائب'],
            'treasury' => ['Treasury', 'الخزائن'],
            'currency' => ['Currency', 'العملات'],
            'bank' => ['Bank', 'البنوك'],
        ];
        $actions = [
            'view' => ['View', 'عرض'],
            'create' => ['Create', 'إضافة'],
            'update' => ['Update', 'تعديل'],
            'delete' => ['Delete', 'حذف'],
        ];

        $now = now();
        $hasKey = Schema::hasColumn('permissions', 'key');
        $hasSlug = Schema::hasColumn('permissions', 'slug');
        $hasName = Schema::hasColumn('permissions', 'name');
        $hasNameAr = Schema::hasColumn('permissions', 'name_ar');
        $hasModule = Schema::hasColumn('permissions', 'module');

        foreach ($entities as $entity => [$entityName, $entityNameAr]) {
            foreach ($actions as $action => [$actionName, $actionNameAr]) {
                $key = "{$entity}.{$action}";
                $where = $hasKey ? ['key' => $key] : ['slug' => $key];
                $values = [];
                if ($hasKey) $values['key'] = $key;
                if ($hasSlug) $values['slug'] = $key;
                if ($hasName) $values['name'] = "{$actionName} {$entityName}";
                if ($hasNameAr) $values['name_ar'] = "{$actionNameAr} {$entityNameAr}";
                if ($hasModule) $values['module'] = $entity;
                if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = $now;
                if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = $now;
                DB::table('permissions')->updateOrInsert($where, $values);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        DB::table('permissions')->whereIn($column, [
            'tax.view', 'tax.create', 'tax.update', 'tax.delete',
            'treasury.view', 'treasury.create', 'treasury.update', 'treasury.delete',
            'currency.view', 'currency.create', 'currency.update', 'currency.delete',
            'bank.view', 'bank.create', 'bank.update', 'bank.delete',
        ])->delete();
    }
};
