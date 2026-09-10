<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables used by ERP features but omitted from the first tenancy migrations. */
    private const TABLES = [
        'cashier_shifts',
        'accounts',
        'transfers',
        'sizes',
        'product_warehouse',
        'offer_product',
        'inventory_variant_stocks',
    ];

    public function up(): void
    {
        $defaultTenantId = DB::table('tenants')->where('slug', 'default')->value('id');

        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tenant_id')) continue;

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            });

            if ($defaultTenantId) {
                DB::table($tableName)->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'tenant_id')) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
