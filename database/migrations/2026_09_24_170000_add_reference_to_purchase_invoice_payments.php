<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_invoice_payments') && !Schema::hasColumn('purchase_invoice_payments', 'reference_number')) {
            Schema::table('purchase_invoice_payments', function (Blueprint $table): void {
                $table->string('reference_number')->nullable()->after('payment_method');
            });
        }
        if (Schema::hasTable('purchase_invoice_payments') && !Schema::hasColumn('purchase_invoice_payments', 'deleted_at')) {
            Schema::table('purchase_invoice_payments', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_invoice_payments') && Schema::hasColumn('purchase_invoice_payments', 'reference_number')) {
            Schema::table('purchase_invoice_payments', function (Blueprint $table): void {
                $table->dropColumn('reference_number');
            });
        }
    }
};
