<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_items')) return;

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('invoice_items', 'item_type')) {
                $table->string('item_type')->default('product')->after('product_id')->index();
            }
            if (!Schema::hasColumn('invoice_items', 'meter_quantity')) {
                $table->decimal('meter_quantity', 14, 3)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('invoice_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoice_items')) return;

        Schema::table('invoice_items', function (Blueprint $table): void {
            foreach (['product_unit_id', 'meter_quantity', 'item_type'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) $table->dropColumn($column);
            }
        });
    }
};
