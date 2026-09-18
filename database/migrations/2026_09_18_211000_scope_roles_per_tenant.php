<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->after('id')->index()->constrained('tenants')->nullOnDelete();
            });
        }

        $indexes = collect(Schema::getIndexes('roles'));
        foreach ($indexes->filter(fn (array $index): bool => $index['unique'] && $index['columns'] === ['name']) as $index) {
            Schema::table('roles', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
        if (!collect(Schema::getIndexes('roles'))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['tenant_id', 'name'])) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'name']);
            });
        }

        $tenants = DB::table('tenants')->pluck('id');
        $globalRoles = DB::table('roles')->whereNull('tenant_id')->get();
        foreach ($tenants as $tenantId) {
            foreach ($globalRoles as $globalRole) {
                $existing = DB::table('roles')->where('tenant_id', $tenantId)->where('name', $globalRole->name)->first();
                $tenantRoleId = $existing?->id;
                if (!$tenantRoleId) {
                    $tenantRoleId = DB::table('roles')->insertGetId([
                        'tenant_id' => $tenantId,
                        'name' => $globalRole->name,
                        'deleted_at' => $globalRole->deleted_at,
                        'created_at' => $globalRole->created_at,
                        'updated_at' => $globalRole->updated_at,
                    ]);
                    if (Schema::hasTable('role_permissions')) {
                        $permissionIds = DB::table('role_permissions')->where('role_id', $globalRole->id)->pluck('permission_id');
                        foreach ($permissionIds as $permissionId) {
                            DB::table('role_permissions')->insertOrIgnore(['role_id' => $tenantRoleId, 'permission_id' => $permissionId]);
                        }
                    }
                }

                foreach (['employees', 'admins'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'role_id') && Schema::hasColumn($table, 'tenant_id')) {
                        DB::table($table)->where('tenant_id', $tenantId)->where('role_id', $globalRole->id)->update(['role_id' => $tenantRoleId]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
    }
};
