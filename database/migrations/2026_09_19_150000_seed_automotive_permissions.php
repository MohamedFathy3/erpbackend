<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) return;

        $groups = [
            'automotive',
            'automotive.dashboard', 'automotive.customers', 'automotive.vehicles',
            'automotive.services', 'automotive.orders', 'automotive.work_logs',
            'automotive.photos', 'automotive.warranties', 'automotive.visits',
            'automotive.payments', 'automotive.reports', 'automotive.portal',
        ];
        $actions = ['view', 'create', 'update', 'delete'];
        $special = [
            'automotive.orders.assign', 'automotive.orders.approve', 'automotive.orders.deliver',
            'automotive.warranties.manage', 'automotive.photos.upload',
            'automotive.portal.customer_login', 'automotive.portal.technician_login',
        ];
        $keys = [];
        foreach ($groups as $group) foreach ($actions as $action) $keys[] = "{$group}.{$action}";
        $keys = array_values(array_unique(array_merge($keys, $special)));
        $hasKey = Schema::hasColumn('permissions', 'key');
        $keyColumn = $hasKey ? 'key' : 'slug';
        $now = now();

        foreach ($keys as $key) {
            $values = [$keyColumn => $key];
            if (Schema::hasColumn('permissions', 'slug')) $values['slug'] = $key;
            if (Schema::hasColumn('permissions', 'name')) $values['name'] = ucwords(str_replace(['.', '_'], ' ', $key));
            if (Schema::hasColumn('permissions', 'name_ar')) $values['name_ar'] = ucwords(str_replace(['.', '_'], ' ', $key));
            if (Schema::hasColumn('permissions', 'created_at')) $values['created_at'] = $now;
            if (Schema::hasColumn('permissions', 'updated_at')) $values['updated_at'] = $now;
            DB::table('permissions')->updateOrInsert([$keyColumn => $key], $values);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $prefixes = ['automotive.dashboard.', 'automotive.customers.', 'automotive.vehicles.', 'automotive.services.', 'automotive.orders.', 'automotive.work_logs.', 'automotive.photos.', 'automotive.warranties.', 'automotive.visits.', 'automotive.payments.', 'automotive.reports.', 'automotive.portal.'];
        $column = Schema::hasColumn('permissions', 'key') ? 'key' : 'slug';
        foreach ($prefixes as $prefix) DB::table('permissions')->where($column, 'like', $prefix . '%')->delete();
    }
};
