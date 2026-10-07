<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'purchase_return_items';
        if (!Schema::hasTable($tableName)) {
            return;
        }

        foreach (['product_unit_id', 'color_id', 'size_id', 'product_variant_id'] as $column) {
            if (Schema::hasColumn($tableName, $column)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->unsignedBigInteger($column)->nullable();
                $table->index($column);
            });
        }
    }

    public function down(): void
    {
        $tableName = 'purchase_return_items';
        if (!Schema::hasTable($tableName)) {
            return;
        }

        foreach (['product_variant_id', 'size_id', 'color_id', 'product_unit_id'] as $column) {
            if (!Schema::hasColumn($tableName, $column)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropIndex([$column]);
                $table->dropColumn($column);
            });
        }
    }
};