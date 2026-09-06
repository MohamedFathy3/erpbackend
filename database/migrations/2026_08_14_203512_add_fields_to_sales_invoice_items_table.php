<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ التعديل الأول: إضافة أعمدة لـ sales_invoice_items
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_invoice_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')
                    ->nullable()
                    ->after('product_id');
            }

            if (!Schema::hasColumn('sales_invoice_items', 'color_id')) {
                $table->unsignedBigInteger('color_id')
                    ->nullable()
                    ->after('product_unit_id');
            }
        });

        // ✅ التعديل الثاني: إضافة invoice_date لـ sales_invoices
        Schema::table('sales_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_invoices', 'invoice_date')) {
                $table->date('invoice_date')
                    ->nullable()
                    ->after('due_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_invoice_items', 'product_unit_id')) {
                $table->dropColumn('product_unit_id');
            }
            if (Schema::hasColumn('sales_invoice_items', 'color_id')) {
                $table->dropColumn('color_id');
            }
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('sales_invoices', 'invoice_date')) {
                $table->dropColumn('invoice_date');
            }
        });
    }
};