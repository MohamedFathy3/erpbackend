<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_invoice_items', 'size_id')) {
                $table->unsignedBigInteger('size_id')->nullable()->after('color_id');
                $table->index('size_id');
            }
            if (!Schema::hasColumn('sales_invoice_items', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->after('size_id');
                $table->index('product_variant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            foreach (['product_variant_id', 'size_id'] as $column) {
                if (Schema::hasColumn('sales_invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
