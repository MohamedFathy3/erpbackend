<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bom_items')) {
            Schema::table('bom_items', function (Blueprint $table): void {
                if (!Schema::hasColumn('bom_items', 'output_product_unit_id')) {
                    $table->unsignedBigInteger('output_product_unit_id')->nullable()->index();
                }
                if (!Schema::hasColumn('bom_items', 'output_color_id')) {
                    $table->unsignedBigInteger('output_color_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('manufacturing_orders')) {
            Schema::table('manufacturing_orders', function (Blueprint $table): void {
                if (!Schema::hasColumn('manufacturing_orders', 'product_unit_id')) {
                    $table->unsignedBigInteger('product_unit_id')->nullable()->index();
                }
                if (!Schema::hasColumn('manufacturing_orders', 'color_id')) {
                    $table->unsignedBigInteger('color_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'bom_items' => ['output_product_unit_id', 'output_color_id'],
            'manufacturing_orders' => ['product_unit_id', 'color_id'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropIndex([$column]);
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
