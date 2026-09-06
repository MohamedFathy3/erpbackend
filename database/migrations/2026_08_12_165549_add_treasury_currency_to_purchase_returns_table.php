<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            // ✅ الخزنة
            $table->foreignId('treasury_id')
                ->nullable()
                ->after('purchase_invoices_id')
                ->constrained('treasuries')
                ->nullOnDelete();
            
            // ✅ العملة
            $table->foreignId('currency_id')
                ->nullable()
                ->after('treasury_id')
                ->constrained('currencies')
                ->nullOnDelete();
            
            // ✅ المخزن
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('currency_id')
                ->constrained('warehouses')
                ->nullOnDelete();
            
            // ✅ المبلغ المدفوع
            $table->decimal('paid_amount', 12, 2)
                ->default(0)
                ->after('total_amount');
            
            // ✅ طريقة الدفع
            $table->string('payment_method')
                ->nullable()
                ->after('paid_amount');
            
            // ✅ التاريخ
            $table->date('return_date')
                ->nullable()
                ->after('payment_method');
            
            // ✅ إضافة indices
            $table->index(['treasury_id', 'currency_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign(['treasury_id']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['warehouse_id']);
            
            $table->dropColumn([
                'treasury_id',
                'currency_id',
                'warehouse_id',
                'paid_amount',
                'payment_method',
                'return_date'
            ]);
        });
    }
};