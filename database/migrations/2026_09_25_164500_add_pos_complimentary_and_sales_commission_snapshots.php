<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (!Schema::hasColumn('invoices', 'is_complimentary')) {
                    $table->boolean('is_complimentary')->default(false);
                }
                if (!Schema::hasColumn('invoices', 'commission_rate_snapshot')) {
                    $table->decimal('commission_rate_snapshot', 5, 2)->nullable();
                }
                if (!Schema::hasColumn('invoices', 'commission_amount_snapshot')) {
                    $table->decimal('commission_amount_snapshot', 12, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('sales_invoices')) {
            Schema::table('sales_invoices', function (Blueprint $table): void {
                if (!Schema::hasColumn('sales_invoices', 'commission_rate_snapshot')) {
                    $table->decimal('commission_rate_snapshot', 5, 2)->nullable();
                }
                if (!Schema::hasColumn('sales_invoices', 'commission_amount_snapshot')) {
                    $table->decimal('commission_amount_snapshot', 12, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table): void {
                if (!Schema::hasColumn('invoice_items', 'discount_percentage')) {
                    $table->decimal('discount_percentage', 7, 2)->default(0);
                }
                if (!Schema::hasColumn('invoice_items', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'invoices' => ['is_complimentary', 'commission_rate_snapshot', 'commission_amount_snapshot'],
            'sales_invoices' => ['commission_rate_snapshot', 'commission_amount_snapshot'],
            'invoice_items' => ['discount_percentage', 'discount_amount'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) continue;
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
