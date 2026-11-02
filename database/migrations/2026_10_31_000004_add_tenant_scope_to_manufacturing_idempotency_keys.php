<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manufacturing_idempotency_keys')) {
            return;
        }

        if (!Schema::hasColumn('manufacturing_idempotency_keys', 'tenant_id')) {
            Schema::table('manufacturing_idempotency_keys', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            });
        }

        if (Schema::hasColumn('manufacturing_idempotency_keys', 'user_id')
            && Schema::hasColumn('users', 'tenant_id')) {
            DB::table('manufacturing_idempotency_keys')
                ->whereNull('tenant_id')
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->chunkById(500, function ($records): void {
                    $tenantIdsByUser = DB::table('users')
                        ->whereIn('id', $records->pluck('user_id')->unique())
                        ->whereNotNull('tenant_id')
                        ->pluck('tenant_id', 'id');

                    foreach ($records as $record) {
                        $tenantId = $tenantIdsByUser[$record->user_id] ?? null;
                        if ($tenantId) {
                            DB::table('manufacturing_idempotency_keys')
                                ->where('id', $record->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                });
        }

        $defaultTenantId = DB::table('tenants')->where('slug', 'default')->value('id');
        if ($defaultTenantId) {
            DB::table('manufacturing_idempotency_keys')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $defaultTenantId]);
        }

        foreach (Schema::getIndexes('manufacturing_idempotency_keys') as $index) {
            if (($index['columns'] ?? []) === ['key', 'action', 'user_id'] && ($index['unique'] ?? false)) {
                Schema::table('manufacturing_idempotency_keys', function (Blueprint $table) use ($index): void {
                    $table->dropUnique($index['name']);
                });
                break;
            }
        }

        $hasTenantUniqueIndex = false;
        foreach (Schema::getIndexes('manufacturing_idempotency_keys') as $index) {
            if (($index['columns'] ?? []) === ['key', 'action', 'user_id', 'tenant_id'] && ($index['unique'] ?? false)) {
                $hasTenantUniqueIndex = true;
                break;
            }
        }

        if (!$hasTenantUniqueIndex) {
            Schema::table('manufacturing_idempotency_keys', function (Blueprint $table): void {
                $table->unique(
                    ['key', 'action', 'user_id', 'tenant_id'],
                    'mo_idempotency_tenant_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Keep this compatibility migration additive to avoid removing tenant data.
    }
};
