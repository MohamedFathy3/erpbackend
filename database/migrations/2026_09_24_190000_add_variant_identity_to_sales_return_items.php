<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_invoice_return_items')) return;

        Schema::table('sales_invoice_return_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('sales_invoice_return_items', 'size_id')) {
                $table->unsignedBigInteger('size_id')->nullable();
            }
            if (!Schema::hasColumn('sales_invoice_return_items', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales_invoice_return_items')) return;
        Schema::table('sales_invoice_return_items', function (Blueprint $table): void {
            foreach (['product_variant_id', 'size_id'] as $column) {
                if (Schema::hasColumn('sales_invoice_return_items', $column)) $table->dropColumn($column);
            }
        });
    }
};
