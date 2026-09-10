<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'purchase_return_items';
        if (!Schema::hasTable($tableName)) return;

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $addColumn = static function (string $column, ?string $after) use ($table, $tableName): void {
                if (Schema::hasColumn($tableName, $column)) return;
                $definition = $table->unsignedBigInteger($column)->nullable();
                if ($after && Schema::hasColumn($tableName, $after)) $definition->after($after);
            };
            $addColumn('color_id', Schema::hasColumn($tableName, 'product_unit_id') ? 'product_unit_id' : null);
            $addColumn('size_id', Schema::hasColumn($tableName, 'color_id') ? 'color_id' : null);
            $addColumn('product_variant_id', Schema::hasColumn($tableName, 'size_id') ? 'size_id' : (Schema::hasColumn($tableName, 'color_id') ? 'color_id' : null));
        });

        $indexes = collect(Schema::getIndexes($tableName))->pluck('columns')->map(fn (array $columns) => implode(',', $columns))->all();
        Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes): void {
            foreach (['color_id', 'size_id', 'product_variant_id'] as $column) {
                if (Schema::hasColumn($tableName, $column) && !in_array($column, $indexes, true)) $table->index($column);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $columns = ['color_id', 'size_id', 'product_variant_id'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('purchase_return_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
