<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inventory_movements', 'inventory_variant_stock_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreignId('inventory_variant_stock_id')
                    ->nullable()
                    ->after('warehouse_id')
                    ->constrained('inventory_variant_stocks')
                    ->nullOnDelete();
                $table->index('inventory_variant_stock_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_movements', 'inventory_variant_stock_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropForeign(['inventory_variant_stock_id']);
                $table->dropIndex(['inventory_variant_stock_id']);
                $table->dropColumn('inventory_variant_stock_id');
            });
        }
    }
};
