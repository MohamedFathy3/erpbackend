<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_variant_stocks') || !Schema::hasColumn('inventory_variant_stocks', 'tenant_id')) {
            return;
        }

        Schema::table('inventory_variant_stocks', function (Blueprint $table): void {
            $table->dropUnique('inventory_variant_stocks_identity_key_unique');
            $table->unique(['tenant_id', 'identity_key'], 'inventory_variant_stocks_tenant_identity_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_variant_stocks') || !Schema::hasColumn('inventory_variant_stocks', 'tenant_id')) {
            return;
        }

        Schema::table('inventory_variant_stocks', function (Blueprint $table): void {
            $table->dropUnique('inventory_variant_stocks_tenant_identity_unique');
            $table->unique('identity_key', 'inventory_variant_stocks_identity_key_unique');
        });
    }
};
