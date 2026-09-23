<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (!Schema::hasColumn('customers', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
                $table->index(['branch_id']);
            }
        });
        Schema::table('invoice_payments', function (Blueprint $table): void {
            if (!Schema::hasColumn('invoice_payments', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('invoice_id')->constrained('employees')->nullOnDelete();
                $table->index(['employee_id', 'created_at']);
            }
        });
        Schema::table('sales_invoice_payments', function (Blueprint $table): void {
            if (!Schema::hasColumn('sales_invoice_payments', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('sales_invoice_id')->constrained('employees')->nullOnDelete();
                $table->index(['employee_id', 'created_at']);
            }
        });
    }
    public function down(): void
    {
        Schema::table('sales_invoice_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_invoice_payments', 'employee_id')) { $table->dropForeign(['employee_id']); $table->dropColumn('employee_id'); }
        });
        Schema::table('invoice_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('invoice_payments', 'employee_id')) { $table->dropForeign(['employee_id']); $table->dropColumn('employee_id'); }
        });
        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'branch_id')) { $table->dropForeign(['branch_id']); $table->dropColumn('branch_id'); }
        });
    }
};
