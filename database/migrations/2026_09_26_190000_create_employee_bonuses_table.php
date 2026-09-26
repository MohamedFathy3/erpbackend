<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('employee_bonuses')) return;
        Schema::create('employee_bonuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('bonus_date');
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->string('status')->default('due');
            $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->nullOnDelete();
            $table->foreignId('finance_id')->nullable()->constrained('finances')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('recorded_by_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'bonus_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('employee_bonuses'); }
};
