<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            if (!Schema::hasColumn('sales_invoices', 'bank_id')) {
                $table->foreignId('bank_id')->nullable()->after('treasury_id')->constrained('banks')->nullOnDelete();
            }
            if (!Schema::hasColumn('sales_invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0)->after('net_total');
            }
            if (!Schema::hasColumn('sales_invoices', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->after('paid_amount')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_invoices', 'bank_id')) {
                $table->dropForeign(['bank_id']);
                $table->dropColumn('bank_id');
            }
            foreach (['paid_amount', 'payment_status'] as $column) {
                if (Schema::hasColumn('sales_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
