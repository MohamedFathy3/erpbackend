<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_invoice_return_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('sales_invoice_return_items', 'color_id')) {
                $table->unsignedBigInteger('color_id')->nullable()->after('product_unit_id');
            }
            if (!Schema::hasColumn('sales_invoice_return_items', 'size')) {
                $table->string('size')->nullable()->after('color_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_return_items', function (Blueprint $table) {
            foreach (['product_unit_id', 'color_id', 'size'] as $column) {
                if (Schema::hasColumn('sales_invoice_return_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
