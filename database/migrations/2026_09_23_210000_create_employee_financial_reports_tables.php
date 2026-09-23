<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('employee_payrolls')) {
            Schema::create('employee_payrolls', function (Blueprint $table) {
                $table->id(); $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->date('period_start'); $table->date('period_end'); $table->decimal('base_salary', 14, 2)->default(0);
                $table->decimal('allowances', 14, 2)->default(0); $table->decimal('deductions', 14, 2)->default(0);
                $table->decimal('advance_deductions', 14, 2)->default(0); $table->decimal('net_salary', 14, 2)->default(0);
                $table->date('due_date')->nullable(); $table->string('status')->default('due'); $table->timestamp('paid_at')->nullable();
                $table->json('adjustments')->nullable(); $table->text('notes')->nullable(); $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
                $table->index(['employee_id', 'period_end'], 'payroll_employee_period_idx');
                $table->unique(['employee_id', 'period_start', 'period_end'], 'payroll_employee_period_unique');
            });
        }
        if (!Schema::hasTable('employee_advances')) {
            Schema::create('employee_advances', function (Blueprint $table) {
                $table->id(); $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->decimal('amount', 14, 2); $table->date('advance_date'); $table->string('reason'); $table->string('recorded_by_name')->nullable();
                $table->decimal('paid_amount', 14, 2)->default(0); $table->date('last_payment_at')->nullable(); $table->string('status')->default('new');
                $table->text('notes')->nullable(); $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
                $table->index(['employee_id', 'advance_date'], 'advance_employee_date_idx');
            });
        }
        if (!Schema::hasTable('employee_advance_payments')) {
            Schema::create('employee_advance_payments', function (Blueprint $table) {
                $table->id(); $table->foreignId('advance_id')->constrained('employee_advances')->cascadeOnDelete(); $table->decimal('amount', 14, 2);
                $table->date('payment_date'); $table->string('recorded_by_name')->nullable(); $table->text('notes')->nullable();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
            });
        }
        if (!Schema::hasTable('employee_financial_transactions')) {
            Schema::create('employee_financial_transactions', function (Blueprint $table) {
                $table->id(); $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->date('transaction_date'); $table->string('type'); $table->string('reason'); $table->decimal('amount', 14, 2);
                $table->text('notes')->nullable(); $table->string('recorded_by_name')->nullable(); $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
                $table->index(['employee_id', 'transaction_date'], 'employee_transaction_date_idx');
            });
        }
    }
    public function down(): void { Schema::dropIfExists('employee_financial_transactions'); Schema::dropIfExists('employee_advance_payments'); Schema::dropIfExists('employee_advances'); Schema::dropIfExists('employee_payrolls'); }
};
